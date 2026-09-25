(()=>{
'use strict';

const C=window.RARWSO||{};
const $=(s,r=document)=>r.querySelector(s);
const $$=(s,r=document)=>Array.from(r.querySelectorAll(s));

let items=[];
let stockTimer;
let orderTimer;
let stockRequest=0;
let orderRequest=0;
let pendingOrderRequestId='';
let stockFilter='all';
let stockPage=1;
let stockHasMore=false;
let stockProducts=new Map();
let activeStockProductId=0;
let managerMode='all';
let managerOrders=[];
let managerPage=1;
let managerHasMore=false;
let managerUndo=new Map();
let lastSlipUrl='';
let statsRetryTimer=null;
let dashboardPeriod='today';
let managerDashboardPeriod='30days';

const esc=s=>String(s??'').replace(/[&<>'"]/g,m=>({
    '&':'&amp;','<':'&lt;','>':'&gt;',"'":'&#039;','"':'&quot;'
}[m]));

function decodeText(value){
    const ta=document.createElement('textarea');
    ta.innerHTML=String(value??'');
    return ta.value.replace(/\u00a0/g,' ').trim();
}

C.currency=decodeText(C.currency||'৳');

function newRequestId(){
    if(window.crypto?.randomUUID)return window.crypto.randomUUID();
    return 'req-'+Date.now()+'-'+Math.random().toString(16).slice(2);
}

function invalidateOrderRequest(){
    pendingOrderRequestId='';
}

function formatNumber(value){
    const decimals=Math.max(0,Number(C.decimals??2));
    const decimalSep=String(C.decimalSep??'.');
    const thousandSep=String(C.thousandSep??',');
    const number=Number(value||0);
    const sign=number<0?'-':'';
    const parts=Math.abs(number).toFixed(decimals).split('.');
    parts[0]=parts[0].replace(/\B(?=(\d{3})+(?!\d))/g,thousandSep);
    return sign+parts[0]+(decimals?decimalSep+parts[1]:'');
}

function money(value){
    const amount=formatNumber(value);
    const symbol=C.currency;
    switch(String(C.currencyPosition||'left')){
        case 'right': return amount+symbol;
        case 'left_space': return symbol+'\u00A0'+amount;
        case 'right_space': return amount+'\u00A0'+symbol;
        default: return symbol+amount;
    }
}

function wordsBelowHundred(n){
    const small=['Zero','One','Two','Three','Four','Five','Six','Seven','Eight','Nine','Ten','Eleven','Twelve','Thirteen','Fourteen','Fifteen','Sixteen','Seventeen','Eighteen','Nineteen'];
    const tens={20:'Twenty',30:'Thirty',40:'Forty',50:'Fifty',60:'Sixty',70:'Seventy',80:'Eighty',90:'Ninety'};
    if(n<20)return small[n];
    const t=Math.floor(n/10)*10;
    const u=n%10;
    return tens[t]+(u?' '+small[u]:'');
}

function integerWords(n){
    n=Math.max(0,Math.floor(Number(n)||0));
    if(n===0)return 'Zero';
    const parts=[];
    const units=[
        [10000000,'Crore'],
        [100000,'Lakh'],
        [1000,'Thousand'],
        [100,'Hundred']
    ];
    for(const [size,label] of units){
        if(n>=size){
            const count=Math.floor(n/size);
            parts.push((count<100?wordsBelowHundred(count):integerWords(count))+' '+label);
            n%=size;
        }
    }
    if(n>0)parts.push(wordsBelowHundred(n));
    return parts.join(' ');
}

function amountWords(value){
    const amount=Math.max(0,Number(value)||0);
    const taka=Math.floor(amount+0.00001);
    const paisa=Math.round((amount-taka)*100);
    return integerWords(taka)+' Taka'+(paisa?' and '+integerWords(paisa)+' Paisa':'')+' Only';
}

function toast(message){
    const el=$('#rar-toast');
    if(!el)return;
    el.textContent=message;
    el.hidden=false;
    clearTimeout(el._t);
    el._t=setTimeout(()=>{el.hidden=true;},3000);
}

async function api(action,data={}){
    const body=new URLSearchParams({action:'rar_wso_'+action,nonce:C.nonce,...data});
    const response=await fetch(C.ajaxUrl,{
        method:'POST',
        credentials:'same-origin',
        headers:{'Content-Type':'application/x-www-form-urlencoded; charset=UTF-8'},
        body
    });

    let json;
    try{
        json=await response.json();
    }catch(_){
        if(response.status===401||response.status===403){
            throw new Error('Your staff session expired. Reload the app and sign in again.');
        }
        throw new Error('Invalid server response.');
    }

    if(json===-1||json===0||response.status===403&&!json?.data?.message){
        const err=new Error('Your staff session expired. Reload the app and sign in again.');
        err.sessionExpired=true;
        throw err;
    }
    if(!response.ok||!json.success){
        throw new Error(json?.data?.message||'Request failed.');
    }

    return json.data;
}

function updateClock(){
    const el=$('#rar-live-clock');
    const orderDate=$('#rar-order-date');
    // Store time from the WordPress timezone offset (works for "+06:00"-style timezones too).
    const offset=Number.isFinite(Number(C.tzOffset))?Number(C.tzOffset):-(new Date().getTimezoneOffset()*60);
    const d=new Date(Date.now()+offset*1000);
    const days=['Sunday','Monday','Tuesday','Wednesday','Thursday','Friday','Saturday'];
    const months=['Jan','Feb','Mar','Apr','May','Jun','Jul','Aug','Sep','Oct','Nov','Dec'];
    const pad=n=>String(n).padStart(2,'0');
    const h=d.getUTCHours();
    const date=months[d.getUTCMonth()]+' '+pad(d.getUTCDate())+', '+d.getUTCFullYear();
    const time=pad(h%12||12)+':'+pad(d.getUTCMinutes())+':'+pad(d.getUTCSeconds())+' '+(h<12?'am':'pm');
    if(el)el.textContent=days[d.getUTCDay()]+' । '+date+' । '+time;
    if(orderDate)orderDate.textContent=date;
}

updateClock();
document.body.classList.add('rar-dashboard-active');
setInterval(updateClock,1000);

function view(name){
    $$('.rar-view').forEach(v=>v.classList.remove('active'));
    $('#rar-'+name)?.classList.add('active');
    document.body.classList.toggle('rar-dashboard-active',name==='dashboard');

    if(name==='stock')loadProducts('',false);
    if(name==='dashboard')loadStats(false);

    window.scrollTo({top:0,behavior:'smooth'});
}

$$('[data-view]').forEach(button=>{
    button.addEventListener('click',()=>view(button.dataset.view));
});

function signedPercent(value){
    const number=Number(value||0);
    if(Math.abs(number)<0.05)return '0%';
    return (number>0?'+':'')+number.toFixed(1)+'%';
}

function comparisonText(value,label='vs previous period'){
    const number=Number(value||0);
    return signedPercent(number)+' '+label;
}

function setText(selector,value){
    const el=$(selector);
    if(el)el.textContent=value;
}

function setWidth(selector,value){
    const el=$(selector);
    if(el)el.style.width=Math.max(0,Math.min(100,Number(value||0)))+'%';
}

function renderStockComposition(data){
    const all=Math.max(1,Number(data.all_stock||0));
    setWidth('#rar-comp-high',Number(data.high_stock||0)/all*100);
    setWidth('#rar-comp-low',Number(data.low_stock||0)/all*100);
    setWidth('#rar-comp-out',Number(data.out_stock||0)/all*100);
    setWidth('#rar-comp-unmanaged',Number(data.unmanaged_stock||0)/all*100);

    setText('#dash-high',Number(data.high_stock||0));
    setText('#dash-low',Number(data.low_stock||0));
    setText('#dash-out',Number(data.out_stock||0));
    setText('#dash-unmanaged',Number(data.unmanaged_stock||0));
    setText('#dash-healthy',Number(data.high_stock||0));
    setText('#dash-low-2',Number(data.low_stock||0));

    const units=Number(data.units_in_hand||0);
    const value=Number(data.stock_value||0);
    setText('#dash-stock-units',formatNumber(units)+' units in hand · stock value '+money(value));
}

function renderTrendLine(trend){
    const chart=$('#rar-sales-chart');
    if(!chart)return;

    const values=(trend?.sales||[]).map(Number);
    const labels=trend?.labels||[];

    if(!values.length){
        chart.innerHTML='<div class="rar-chart-message">No sales data available.</div>';
        return;
    }

    const max=Math.max(1,...values);
    const width=760;
    const height=190;
    const pad=18;
    const usableW=width-pad*2;
    const usableH=height-44;
    const points=values.map((value,index)=>{
        const x=pad+(values.length===1?usableW/2:(usableW*index/(values.length-1)));
        const y=pad+usableH-(value/max*usableH);
        return [x,y];
    });

    const polygon=[[points[0][0],height-28],...points,[points[points.length-1][0],height-28]]
        .map(point=>point.join(',')).join(' ');
    const polyline=points.map(point=>point.join(',')).join(' ');

    chart.innerHTML=
        '<svg viewBox="0 0 '+width+' '+height+'" preserveAspectRatio="none" aria-hidden="true">'+
            '<defs><linearGradient id="rarTrendFill" x1="0" y1="0" x2="0" y2="1"><stop offset="0%" stop-color="#2dd4bf" stop-opacity=".25"/><stop offset="100%" stop-color="#2dd4bf" stop-opacity="0"/></linearGradient></defs>'+
            '<line x1="'+pad+'" y1="'+(height-28)+'" x2="'+(width-pad)+'" y2="'+(height-28)+'" class="rar-chart-axis"/>'+
            '<polygon points="'+polygon+'" fill="url(#rarTrendFill)"/>'+
            '<polyline points="'+polyline+'" class="rar-chart-line"/>'+
            points.map((point,index)=>'<circle cx="'+point[0]+'" cy="'+point[1]+'" r="'+(index===points.length-1?5:3)+'" class="rar-chart-dot"/>').join('')+
        '</svg>'+
        '<div class="rar-chart-labels">'+labels.map((label,index)=>'<span class="'+(index===labels.length-1?'active':'')+'">'+esc(label)+'</span>').join('')+'</div>';
}

function renderSevenDayBars(trend){
    const box=$('#rar-seven-chart');
    if(!box)return;

    const sales=(trend?.sales||[]).map(Number);
    const labels=trend?.labels||[];
    const max=Math.max(1,...sales);
    const total=sales.reduce((sum,value)=>sum+value,0);
    setText('#rar-seven-total',money(total)+' total');

    box.innerHTML=sales.map((value,index)=>{
        const height=Math.max(6,value/max*100);
        return '<div class="rar-mini-bar">'+
            '<span class="value">'+esc(value?money(value):'')+'</span>'+
            '<div><i style="height:'+height+'%"></i></div>'+
            '<small class="'+(index===sales.length-1?'active':'')+'">'+esc(labels[index]||'')+'</small>'+
        '</div>';
    }).join('');
}

function renderAttention(items){
    const box=$('#rar-attention-list');
    if(!box)return;

    const list=Array.isArray(items)?items:[];
    setText('#rar-attention-count',list.length+' '+(list.length===1?'item':'items'));

    box.innerHTML=list.length?list.map(item=>
        '<button type="button" class="rar-attention-item attention-'+esc(item.type||'info')+'" '+
            (item.filter?'data-attention-filter="'+esc(item.filter)+'" ':'')+
            (item.mode?'data-attention-mode="'+esc(item.mode)+'" ':'')+'>'+
            '<b>'+esc(item.count||0)+'</b><span><strong>'+esc(item.title||'Needs attention')+'</strong><small>'+esc(item.detail||'')+'</small></span><i>›</i>'+
        '</button>'
    ).join(''):'<div class="rar-dashboard-empty">Nothing urgent right now.</div>';
}

function renderRecentOrders(orders){
    const box=$('#rar-recent-orders');
    if(!box)return;

    const list=Array.isArray(orders)?orders:[];
    box.innerHTML=list.length?list.map(order=>
        '<article class="rar-recent-order">'+
            '<div class="rar-recent-main"><strong>#'+esc(order.number)+' · '+esc(order.customer)+'</strong>'+
            '<small>'+esc(order.created||'')+' · '+esc(order.items||0)+' item'+(Number(order.items||0)===1?'':'s')+(order.city?' · '+esc(order.city):'')+'</small></div>'+
            '<div class="rar-recent-total"><strong>'+esc(money(order.total))+'</strong><small>'+esc(order.payment||'')+'</small></div>'+
            '<span class="rar-status-pill status-'+esc(order.status||'')+'">● '+esc(order.status_label||order.status||'')+'</span>'+
        '</article>'
    ).join(''):'<div class="rar-dashboard-empty">No recent orders to show.</div>';
}

function renderTopProducts(items,label){
    const box=$('#rar-top-products');
    if(!box)return;

    const list=Array.isArray(items)?items:[];
    setText('#rar-top-products-meta',label?'By quantity · '+label:'By quantity sold');

    box.innerHTML=list.length?list.map((item,index)=>
        '<div class="rar-breakdown-row">'+
            '<b>'+(index+1)+'</b>'+
            '<span><strong>'+esc(item.name||'Product')+'</strong><small>'+esc(formatNumber(item.qty||0))+' sold</small></span>'+
            '<em>'+esc(money(item.sales||0))+'</em>'+
        '</div>'
    ).join(''):'<div class="rar-dashboard-empty">No product sales in this period.</div>';
}

function renderMix(selector,items){
    const box=$(selector);
    if(!box)return;

    const list=Array.isArray(items)?items:[];
    box.innerHTML=list.length?list.map(item=>{
        const pct=Math.max(0,Math.min(100,Number(item.share_pct||0)));
        return '<div class="rar-mix-row">'+
            '<div><strong>'+esc(item.label||'Other')+'</strong><span>'+esc(item.orders||0)+' order'+(Number(item.orders||0)===1?'':'s')+'</span><em>'+esc(money(item.sales||0))+'</em></div>'+
            '<div class="rar-mix-track"><i style="width:'+pct+'%"></i></div>'+
            '<small>'+pct.toFixed(1)+'%</small>'+
        '</div>';
    }).join(''):'<div class="rar-dashboard-empty">No data in this period.</div>';
}

function renderManagerDashboard(data){
    if(!C.isManager)return;

    const control=data.order_control||{};
    setText('#manager-all-orders',Number(control.all||0));
    setText('#manager-live-orders',Number(control.live||0));
    setText('#manager-processing-orders',Number(control.processing||0));

    const manager=data.manager_period?.current||{};
    const previous=data.manager_period?.previous||{};
    const comparison=data.manager_period?.current?.comparison||{};

    setText('#mgr-sales-label','Sales · '+(data.manager_period?.label||''));
    setText('#mgr-sales',money(manager.sales||0));
    setText('#mgr-orders',Number(manager.orders||0));
    setText('#mgr-average',money(manager.average_order||0));
    setText('#mgr-items',Number(manager.items_sold||0));
    setText('#mgr-sales-change',comparisonText(comparison.sales_pct));
    setText('#mgr-orders-change',comparisonText(comparison.orders_pct));

    const analytics=data.analytics||{};
    const weekTotal=Number(analytics.week_total||0);
    setText('#rar-week-sales',money(weekTotal)+' in 7 days');

    const growth=Number(analytics.growth_pct||0);
    const growthEl=$('#rar-growth');
    if(growthEl){
        growthEl.textContent=comparisonText(growth,'vs previous 7 days');
        growthEl.classList.toggle('negative',growth<0);
        growthEl.classList.toggle('positive',growth>=0);
    }

    renderTrendLine(data.trend||analytics);

    const breakdowns=data.manager_breakdowns||{};
    renderTopProducts(breakdowns.top_products||[],data.manager_period?.label||'');
    renderMix('#rar-payment-mix',breakdowns.payment_mix||[]);
    renderMix('#rar-channel-mix',breakdowns.channel_mix||[]);
}

function renderDashboard(data){
    const period=data.period||{};
    const current=period.current||{};
    const comparison=current.comparison||{};

    setText('#stat-orders-label',period.key==='today'?"Today's Orders":'Orders · '+(period.label||''));
    setText('#stat-sales-label',period.key==='today'?"Today's Sales":'Sales · '+(period.label||''));
    setText('#stat-orders',Number(current.orders||0));
    setText('#stat-sales',money(current.sales||0));
    setText('#stat-completed-period',Number(current.completed||0));
    setText('#stat-returned-period',Number(current.returned_cancelled||0));

    setText('#stat-orders-sub',comparisonText(comparison.orders_pct));
    setText('#stat-sales-sub',comparisonText(comparison.sales_pct));
    setText('#stat-completed-sub',(current.orders?Math.round(Number(current.completed||0)/Number(current.orders)*100):0)+'% of period orders');
    setText('#stat-returned-sub',Number(current.returned_cancelled||0)+' exception'+(Number(current.returned_cancelled||0)===1?'':'s'));

    setText('#stat-all-stock',Number(data.all_stock||0));
    setText('#stat-available',Number(data.available_stock||0));
    setText('#stat-out',Number(data.out_stock||0));

    setText('#stock-count-all',Number(data.all_stock||0));
    setText('#stock-count-high',Number(data.high_stock||0));
    setText('#stock-count-low',Number(data.low_stock||0));
    setText('#stock-count-out',Number(data.out_stock||0));
    setText('#stock-count-unmanaged',Number(data.unmanaged_stock||0));

    renderStockComposition(data);
    renderSevenDayBars(data.trend||{});
    renderAttention(data.needs_attention||[]);
    renderRecentOrders(data.recent_orders||[]);
    renderManagerDashboard(data);
}

function scheduleStatsRetry(){
    clearTimeout(statsRetryTimer);
    statsRetryTimer=setTimeout(()=>{
        if(!document.hidden)loadStats(false);
    },5000);
}

async function loadStats(showError=true){
    try{
        const data=await api('stats',{
            period:dashboardPeriod,
            manager_period:managerDashboardPeriod
        });

        clearTimeout(statsRetryTimer);
        renderDashboard(data);
    }catch(error){
        if(error&&error.sessionExpired){
            toast(error.message);
            return;
        }
        if(showError)toast('Dashboard data could not load. Retrying…');
        scheduleStatsRetry();
    }
}

$$('[data-dashboard-period]').forEach(button=>{
    button.addEventListener('click',()=>{
        dashboardPeriod=button.dataset.dashboardPeriod||'today';
        $$('[data-dashboard-period]').forEach(item=>item.classList.toggle('active',item===button));
        loadStats();
    });
});

$$('[data-manager-period]').forEach(button=>{
    button.addEventListener('click',()=>{
        managerDashboardPeriod=button.dataset.managerPeriod||'30days';
        $$('[data-manager-period]').forEach(item=>item.classList.toggle('active',item===button));
        loadStats();
    });
});

$('#rar-attention-list')?.addEventListener('click',event=>{
    const button=event.target.closest('.rar-attention-item');
    if(!button)return;

    if(button.dataset.attentionFilter){
        setStockFilter(button.dataset.attentionFilter);
        view('stock');
        return;
    }

    if(C.isManager&&button.dataset.attentionMode){
        view('orders');
        loadManagerOrders(button.dataset.attentionMode,false);
    }
});

function stockBandLabel(product){
    if(product.stock_band==='high')return 'Healthy';
    if(product.stock_band==='low')return 'Low stock';
    if(product.stock_band==='out')return 'Out of stock';
    return 'Stock unmanaged';
}

function productMeta(product){
    const meta=[];
    if(product.sku)meta.push('SKU: '+esc(product.sku));
    meta.push('Price: '+esc(money(product.price)));
    if(product.regular_price!==null && Number(product.regular_price)>Number(product.price)+0.0001){
        meta.push('Regular: '+esc(money(product.regular_price)));
    }
    if(product.manage_stock){
        meta.push('Stock: '+esc(product.stock_qty));
    }else{
        meta.push('Stock not managed');
    }
    meta.push(stockBandLabel(product));
    return meta.join(' · ');
}

function productRow(product){
    const managed=Boolean(product.manage_stock);
    const qtyValue=managed?esc(product.stock_qty):'';
    const buttonText=managed?'Save':'Set stock';
    const band='band-'+esc(product.stock_band||'unmanaged');

    return '<div class="rar-product-row '+band+'" data-id="'+product.id+'">'+
        '<img src="'+esc(product.image)+'" alt="">'+
        '<div class="rar-product-info">'+
            '<div class="rar-product-name">'+esc(product.name)+'</div>'+
            '<div class="rar-product-meta">'+productMeta(product)+'</div>'+
            '<span class="rar-stock-badge '+band+'">'+esc(stockBandLabel(product))+'</span>'+
        '</div>'+
        '<div class="rar-stock-control">'+
            '<input class="stock-qty" type="number" min="0" step="1" inputmode="numeric" placeholder="—" value="'+qtyValue+'">'+
            '<div class="rar-stock-row-actions"><button data-save="'+product.id+'" type="button">'+buttonText+'</button>'+
            '<button class="rar-stock-open" data-open-stock="'+product.id+'" type="button">Quick</button></div>'+
        '</div>'+
    '</div>';
}

function setStockFilter(filter){
    stockFilter=filter||'all';
    stockPage=1;
    $$('.rar-stock-summary [data-stock-filter]').forEach(btn=>{
        btn.classList.toggle('active',btn.dataset.stockFilter===stockFilter);
    });
}

async function loadProducts(query='',append=false){
    const list=$('#rar-stock-list');
    if(!list)return;

    const requestId=++stockRequest;
    if(!append)list.innerHTML='<div class="rar-empty">Loading inventory…</div>';

    try{
        const data=await api('products',{search:query,filter:stockFilter,page:stockPage});
        if(requestId!==stockRequest)return;

        (data.items||[]).forEach(product=>stockProducts.set(Number(product.id),product));
        const html=(data.items||[]).map(productRow).join('');
        if(append){
            list.insertAdjacentHTML('beforeend',html);
        }else{
            list.innerHTML=html||'<div class="rar-empty">No matching products found.</div>';
        }

        stockHasMore=Boolean(data.has_more);
        const more=$('#rar-stock-more');
        if(more)more.hidden=!stockHasMore;
    }catch(error){
        if(requestId!==stockRequest)return;
        if(!append)list.innerHTML='<div class="rar-empty">'+esc(error.message)+'</div>';
        toast(error.message);
    }
}

$$('[data-stock-filter]').forEach(button=>{
    button.addEventListener('click',()=>{
        const filter=button.dataset.stockFilter||'all';
        setStockFilter(filter);
        if(!$('#rar-stock')?.classList.contains('active'))view('stock');
        else loadProducts($('#rar-stock-search')?.value.trim()||'',false);
    });
});

$('#rar-stock-search')?.addEventListener('input',event=>{
    clearTimeout(stockTimer);
    stockPage=1;
    const query=event.target.value.trim();
    stockTimer=setTimeout(()=>loadProducts(query,false),260);
});

$('#rar-stock-more')?.addEventListener('click',()=>{
    if(!stockHasMore)return;
    stockPage++;
    loadProducts($('#rar-stock-search')?.value.trim()||'',true);
});

function stockMovementLabel(source){
    if(source==='quick_adjust')return 'Quick adjust';
    if(source==='manual_set')return 'Set quantity';
    return 'Stock change';
}

function renderStockHistory(history){
    const box=$('#rar-stock-history');
    if(!box)return;

    const list=Array.isArray(history)?history:[];
    box.innerHTML=list.length?list.map(entry=>{
        const delta=Number(entry.delta||0);
        const from=entry.from===null?'Not tracked':formatNumber(entry.from);
        const to=formatNumber(entry.to||0);
        return '<article class="rar-movement-row">'+
            '<div><strong>'+esc(stockMovementLabel(entry.source))+'</strong><small>'+esc(entry.time||'')+' · '+esc(entry.user||'')+'</small></div>'+
            '<div class="rar-movement-values"><span>'+esc(from)+' → '+esc(to)+'</span><b class="'+(delta>0?'up':delta<0?'down':'')+'">'+(delta>0?'+':'')+esc(formatNumber(delta))+'</b></div>'+
        '</article>';
    }).join(''):'<div class="rar-dashboard-empty">No manual stock movements recorded yet.</div>';
}

function syncStockModalProduct(product){
    if(!product)return;
    activeStockProductId=Number(product.id||0);
    stockProducts.set(activeStockProductId,product);

    const image=$('#rar-stock-modal-image');
    if(image)image.src=product.image||'';
    setText('#rar-stock-modal-name',product.name||'Product');
    setText('#rar-stock-modal-sku',product.sku?'SKU '+product.sku:'No SKU');

    const band=$('#rar-stock-modal-band');
    if(band){
        band.className='rar-stock-badge band-'+String(product.stock_band||'unmanaged');
        band.textContent=stockBandLabel(product);
    }

    const current=product.manage_stock?Number(product.stock_qty||0):0;
    setText('#rar-stock-modal-current',product.manage_stock?formatNumber(current):'Not tracked');
    const input=$('#rar-stock-modal-qty');
    if(input)input.value=product.manage_stock?current:'';
}

async function loadStockHistory(productId=activeStockProductId){
    if(!productId)return;
    const box=$('#rar-stock-history');
    if(box)box.innerHTML='<div class="rar-dashboard-loading">Loading movement log…</div>';

    try{
        const data=await api('stock_history',{product_id:productId});
        syncStockModalProduct(data.product);
        renderStockHistory(data.history);
    }catch(error){
        if(box)box.innerHTML='<div class="rar-dashboard-empty">'+esc(error.message)+'</div>';
        toast(error.message);
    }
}

function openStockModal(product){
    if(!product)return;
    const modal=$('#rar-stock-modal');
    if(!modal)return;

    syncStockModalProduct(product);
    modal.hidden=false;
    modal.setAttribute('aria-hidden','false');
    document.body.classList.add('rar-modal-open');
    loadStockHistory(product.id);
}

function closeStockModal(){
    const modal=$('#rar-stock-modal');
    if(!modal)return;
    modal.hidden=true;
    modal.setAttribute('aria-hidden','true');
    document.body.classList.remove('rar-modal-open');
    activeStockProductId=0;
}

$$('[data-close-stock-modal]').forEach(button=>button.addEventListener('click',closeStockModal));
document.addEventListener('keydown',event=>{
    if(event.key==='Escape'&&!$('#rar-stock-modal')?.hidden)closeStockModal();
});

$('#rar-stock-history-refresh')?.addEventListener('click',()=>loadStockHistory());

async function refreshStockViews(product,data){
    if(product)syncStockModalProduct(product);
    if(data?.history)renderStockHistory(data.history);
    stockPage=1;
    await loadProducts($('#rar-stock-search')?.value.trim()||'',false);
    loadStats(false);
}

$('#rar-stock-modal')?.addEventListener('click',async event=>{
    const quick=event.target.closest('[data-stock-delta]');
    if(!quick||!activeStockProductId)return;

    const delta=Number(quick.dataset.stockDelta||0);
    const old=quick.textContent;
    quick.disabled=true;
    quick.textContent='…';

    try{
        const data=await api('stock_adjust',{product_id:activeStockProductId,delta});
        toast(data.message);
        await refreshStockViews(data.product,data);
    }catch(error){
        toast(error.message);
    }finally{
        quick.disabled=false;
        quick.textContent=old;
    }
});

$('#rar-stock-modal-save')?.addEventListener('click',async event=>{
    if(!activeStockProductId)return;
    const input=$('#rar-stock-modal-qty');
    const qty=String(input?.value??'').trim();
    if(qty===''||Number(qty)<0){
        toast('Enter a valid stock quantity.');
        input?.focus();
        return;
    }

    const button=event.currentTarget;
    const old=button.textContent;
    button.disabled=true;
    button.textContent='Saving…';

    try{
        const data=await api('stock_update',{product_id:activeStockProductId,qty});
        toast(data.message);
        await loadStockHistory(activeStockProductId);
        await refreshStockViews(data.product);
    }catch(error){
        toast(error.message);
    }finally{
        button.disabled=false;
        button.textContent=old;
    }
});

$('#rar-stock-list')?.addEventListener('click',async event=>{
    const open=event.target.closest('[data-open-stock]');
    if(open){
        const product=stockProducts.get(Number(open.dataset.openStock));
        if(product)openStockModal(product);
        return;
    }

    const save=event.target.closest('[data-save]');
    if(!save)return;

    const row=save.closest('.rar-product-row');
    const input=$('.stock-qty',row);
    const qty=input.value.trim();

    if(qty===''){
        toast('Enter a stock quantity first.');
        input.focus();
        return;
    }

    const oldText=save.textContent;
    save.disabled=true;
    save.textContent='Saving…';

    try{
        const data=await api('stock_update',{product_id:save.dataset.save,qty});
        toast(data.message);
        stockPage=1;
        await loadProducts($('#rar-stock-search')?.value.trim()||'',false);
        loadStats();
    }catch(error){
        toast(error.message);
    }finally{
        save.disabled=false;
        save.textContent=oldText;
    }
});

function clientFilter(products,query){
    const q=String(query||'').trim().toLowerCase();
    if(!q)return products;
    return products.filter(product=>
        String(product.name||'').toLowerCase().includes(q) ||
        String(product.sku||'').toLowerCase().includes(q)
    );
}

function showOrderSearch(products,query){
    const box=$('#rar-order-search-results');
    const filtered=clientFilter(products||[],query);

    if(!filtered.length){
        box.innerHTML='<div class="rar-empty">No matching products.</div>';
        box._data=[];
        box.classList.add('open');
        return;
    }

    box.innerHTML=filtered.map(product=>{
        const band='band-'+esc(product.stock_band||'unmanaged');
        const stock=product.manage_stock?'Stock '+esc(product.stock_qty):'Stock —';
        const disabled=!product.can_add;
        return '<div class="rar-search-hit '+band+'">'+
            '<img src="'+esc(product.image)+'" alt="">'+
            '<div><strong>'+esc(product.name)+'</strong>'+
            '<small>'+(product.sku?'SKU '+esc(product.sku)+' · ':'')+esc(money(product.price))+' · '+stock+'</small>'+
            '<span class="rar-stock-badge '+band+'">'+esc(stockBandLabel(product))+'</span></div>'+
            '<button type="button" data-id="'+product.id+'" '+(disabled?'disabled':'')+'>'+(disabled?'Out':'Add')+'</button>'+
        '</div>';
    }).join('');

    box._data=filtered;
    box.classList.add('open');
}

$('#rar-order-search')?.addEventListener('input',event=>{
    clearTimeout(orderTimer);
    const query=event.target.value.trim();

    if(query.length<2){
        orderRequest++;
        $('#rar-order-search-results')?.classList.remove('open');
        return;
    }

    const requestId=++orderRequest;
    orderTimer=setTimeout(async()=>{
        try{
            const data=await api('products',{search:query,filter:'all',page:1});
            if(requestId!==orderRequest)return;
            showOrderSearch(data.items,query);
        }catch(error){
            if(requestId!==orderRequest)return;
            toast(error.message);
        }
    },230);
});

$('#rar-order-search-results')?.addEventListener('click',event=>{
    const button=event.target.closest('[data-id]');
    if(!button||button.disabled)return;

    const box=$('#rar-order-search-results');
    const product=box._data?.find(x=>String(x.id)===button.dataset.id);
    if(!product||!product.can_add)return;

    const existing=items.find(item=>item.id===product.id);
    const maxStock=product.manage_stock&&product.stock_qty!==''?Number(product.stock_qty):null;

    if(existing){
        if(maxStock!==null&&existing.qty>=maxStock){
            toast('No more available stock for this item.');
            return;
        }
        existing.qty++;
    }else{
        items.push({
            id:product.id,
            name:product.name,
            sku:product.sku,
            image:product.image||'',
            qty:1,
            price:Number(product.price),
            maxStock,
            stockBand:product.stock_band
        });
    }

    invalidateOrderRequest();
    renderItems();
    $('#rar-order-search').value='';
    box.classList.remove('open');
});

function renderItems(){
    const box=$('#rar-order-items');
    if(!box)return;

    box.innerHTML=items.map((item,index)=>
        '<div class="rar-order-item" data-index="'+index+'">'+
            '<div class="sl">'+(index+1)+'</div>'+
            '<div class="title">'+esc(item.name)+(item.sku?'<small>SKU '+esc(item.sku)+'</small>':'')+'</div>'+
            '<input class="item-qty" type="number" min="1" step="1" inputmode="numeric" value="'+item.qty+'" '+(item.maxStock!==null?'max="'+item.maxStock+'"':'')+'>'+
            '<input class="item-price" type="number" min="0" step=".01" inputmode="decimal" value="'+Number(item.price).toFixed(2)+'" '+(C.allowPrice?'':'readonly')+'>'+
            '<button type="button" class="remove" aria-label="Remove item">×</button>'+
        '</div>'
    ).join('');

    $('#rar-item-count').textContent=items.length+' item'+(items.length===1?'':'s');
    calculateTotals();
}

$('#rar-order-items')?.addEventListener('input',event=>{
    const row=event.target.closest('.rar-order-item');
    if(!row)return;

    const index=Number(row.dataset.index);
    const item=items[index];
    if(!item)return;

    if(event.target.classList.contains('item-qty')){
        let qty=Math.max(1,parseInt(event.target.value||1,10));
        if(item.maxStock!==null&&qty>item.maxStock){
            qty=item.maxStock;
            event.target.value=qty;
            toast('Quantity adjusted to available stock.');
        }
        item.qty=qty;
    }

    if(event.target.classList.contains('item-price')&&C.allowPrice){
        item.price=Math.max(0,Number(event.target.value)||0);
    }

    invalidateOrderRequest();
    calculateTotals();
});

$('#rar-order-items')?.addEventListener('click',event=>{
    if(!event.target.classList.contains('remove'))return;
    items.splice(Number(event.target.closest('.rar-order-item').dataset.index),1);
    invalidateOrderRequest();
    renderItems();
});

['#rar-shipping','#rar-discount-value','#rar-discount-type'].forEach(selector=>{
    $(selector)?.addEventListener('input',()=>{
        invalidateOrderRequest();
        calculateTotals();
    });
    $(selector)?.addEventListener('change',()=>{
        invalidateOrderRequest();
        calculateTotals();
    });
});

function totals(){
    const subtotal=items.reduce((sum,item)=>sum+item.price*item.qty,0);
    const shipping=Math.max(0,Number($('#rar-shipping')?.value||0));
    const discountType=$('#rar-discount-type')?.value||'fixed';
    const rawDiscount=Math.max(0,Number($('#rar-discount-value')?.value||0));
    const discount=discountType==='percent'
        ? Math.min(subtotal,subtotal*Math.min(100,rawDiscount)/100)
        : Math.min(subtotal,rawDiscount);
    const total=Math.max(0,subtotal-discount+shipping);

    return {subtotal,shipping,discount,discountType,discountValue:rawDiscount,total};
}

function calculateTotals(){
    const t=totals();
    $('#rar-subtotal').textContent=money(t.subtotal);
    const discountRow=$('#rar-discount-amount-row');
    if(discountRow){
        discountRow.hidden=!(t.discount>0);
        $('#rar-discount-amount').textContent='− '+money(t.discount);
    }
    $('#rar-total').textContent=money(t.total);
    $('#rar-in-words').textContent=amountWords(t.total);
}

function canonicalFromList(value,list){
    const input=String(value||'').trim().toLowerCase();
    if(!input)return '';
    return (list||[]).find(x=>String(x).toLowerCase()===input)||'';
}

function renderPicker(input,box,options){
    if(!input||!box)return;
    const q=input.value.trim().toLowerCase();
    const matched=(options||[]).filter(x=>String(x).toLowerCase().includes(q)).slice(0,18);

    box.innerHTML=matched.length
        ? matched.map(x=>'<button type="button" data-value="'+esc(x)+'">'+esc(x)+'</button>').join('')
        : '<div class="rar-picker-empty">No matching option</div>';

    box.classList.add('open');
}

function closePickers(except=null){
    $$('.rar-picker-options.open').forEach(box=>{
        if(box!==except)box.classList.remove('open');
    });
}

const districtInput=$('#rar-district');
const districtBox=$('#rar-district-options');
const cityInput=$('#rar-city');
const cityBox=$('#rar-city-options');

function syncCityForDistrict(clearCity=true){
    const district=canonicalFromList(districtInput?.value,C.districts||[]);
    if(!cityInput)return district;

    if(!district){
        cityInput.disabled=true;
        cityInput.placeholder='Select district first';
        if(clearCity)cityInput.value='';
        cityBox?.classList.remove('open');
        return '';
    }

    cityInput.disabled=false;
    cityInput.placeholder='Search town / city / upazila…';
    if(clearCity)cityInput.value='';
    return district;
}

districtInput?.addEventListener('focus',()=>{
    renderPicker(districtInput,districtBox,C.districts||[]);
});
districtInput?.addEventListener('input',()=>{
    syncCityForDistrict(true);
    renderPicker(districtInput,districtBox,C.districts||[]);
    invalidateOrderRequest();
});
districtBox?.addEventListener('click',event=>{
    const button=event.target.closest('[data-value]');
    if(!button)return;
    districtInput.value=button.dataset.value;
    districtBox.classList.remove('open');
    syncCityForDistrict(true);
    cityInput?.focus();
    invalidateOrderRequest();
});

cityInput?.addEventListener('focus',()=>{
    const district=canonicalFromList(districtInput?.value,C.districts||[]);
    if(!district)return;
    renderPicker(cityInput,cityBox,C.cityMap?.[district]||[]);
});
cityInput?.addEventListener('input',()=>{
    const district=canonicalFromList(districtInput?.value,C.districts||[]);
    renderPicker(cityInput,cityBox,C.cityMap?.[district]||[]);
    invalidateOrderRequest();
});
cityBox?.addEventListener('click',event=>{
    const button=event.target.closest('[data-value]');
    if(!button)return;
    cityInput.value=button.dataset.value;
    cityBox.classList.remove('open');
    invalidateOrderRequest();
});

document.addEventListener('click',event=>{
    if(!event.target.closest('.rar-picker-field'))closePickers();
});

function normalizeBdPhone(value){
    const v=String(value||'').replace(/[\s()-]/g,'');
    if(/^01[3-9]\d{8}$/.test(v))return '+88'+v;
    if(/^8801[3-9]\d{8}$/.test(v))return '+'+v;
    if(/^\+8801[3-9]\d{8}$/.test(v))return v;
    return '';
}

$('#rar-order-form')?.addEventListener('input',event=>{
    if(event.target.id==='rar-order-search')return;
    invalidateOrderRequest();
});

function wrapLines(ctx,text,maxWidth){
    const words=String(text||'').split(/\s+/);
    const lines=[];
    let line='';

    for(const word of words){
        const test=line?line+' '+word:word;
        if(ctx.measureText(test).width>maxWidth&&line){
            lines.push(line);
            line=word;
        }else{
            line=test;
        }
    }

    if(line)lines.push(line);
    return lines;
}

function canvasBlob(canvas){
    return new Promise((resolve,reject)=>{
        canvas.toBlob(blob=>blob?resolve(blob):reject(new Error('Could not create slip image.')),'image/png',1);
    });
}

function loadImageSafe(src){
    return new Promise(resolve=>{
        if(!src){
            resolve(null);
            return;
        }

        let settled=false;
        const img=new Image();
        const finish=value=>{
            if(settled)return;
            settled=true;
            clearTimeout(timer);
            resolve(value);
        };

        try{
            const u=new URL(src,window.location.href);
            if(u.origin!==window.location.origin)img.crossOrigin='anonymous';
        }catch(_){}

        img.onload=()=>finish(img);
        img.onerror=()=>finish(null);
        const timer=setTimeout(()=>finish(null),3500);
        img.src=src;
    });
}

async function buildSlipFile(order,snapshot){
    // Measure every wrapped block first so long orders never overlap the footer.
    const mctx=document.createElement('canvas').getContext('2d');
    mctx.font='400 24px Arial';
    const customerText=[snapshot.name,snapshot.phone+(snapshot.email?' · '+snapshot.email:''),snapshot.address+', '+snapshot.city+', '+snapshot.district];
    const customerLineCount=customerText.reduce((sum,text)=>sum+wrapLines(mctx,text,920).length,0);
    mctx.font='400 23px Arial';
    const rowsHeight=snapshot.items.reduce((sum,item)=>sum+Math.max(92,wrapLines(mctx,item.name,420).length*29+24),0);
    mctx.font='400 22px Arial';
    const wordLineCount=wrapLines(mctx,order.amount_words||amountWords(order.total),800).length;
    const noteHeight=snapshot.note?Math.max(60,wrapLines(mctx,snapshot.note,820).length*30+20):0;
    const contentEnd=245+42+customerLineCount*34+18+48+28+38+rowsHeight+10+44+4*42+70+wordLineCount*20+noteHeight;
    const height=Math.max(1450,Math.ceil(contentEnd+190));
    const canvas=document.createElement('canvas');
    canvas.width=1080;
    canvas.height=height;
    const ctx=canvas.getContext('2d');

    ctx.fillStyle='#ffffff';
    ctx.fillRect(0,0,canvas.width,canvas.height);

    ctx.fillStyle='#0f7b63';
    ctx.fillRect(0,0,canvas.width,190);

    ctx.fillStyle='#ffffff';
    ctx.font='700 44px Arial';
    ctx.fillText(C.storeName||'Sales Order',70,78);
    ctx.font='600 30px Arial';
    ctx.fillText('SALES ORDER SLIP',70,128);

    ctx.textAlign='right';
    ctx.font='700 30px Arial';
    ctx.fillText('#'+String(order.order_num),1010,82);
    ctx.font='400 24px Arial';
    ctx.fillText(order.order_date||'',1010,125);
    ctx.textAlign='left';

    let y=245;
    ctx.fillStyle='#111827';
    ctx.font='700 28px Arial';
    ctx.fillText('Customer',70,y);
    y+=42;
    ctx.font='400 24px Arial';

    const customerLines=[
        snapshot.name,
        snapshot.phone+(snapshot.email?' · '+snapshot.email:''),
        snapshot.address+', '+snapshot.city+', '+snapshot.district
    ];

    for(const text of customerLines){
        for(const line of wrapLines(ctx,text,920)){
            ctx.fillText(line,70,y);
            y+=34;
        }
    }

    y+=18;
    ctx.strokeStyle='#dfe6e9';
    ctx.lineWidth=2;
    ctx.beginPath();ctx.moveTo(70,y);ctx.lineTo(1010,y);ctx.stroke();
    y+=48;

    ctx.font='700 25px Arial';
    ctx.fillText('SL',70,y);
    ctx.fillText('IMAGE',125,y);
    ctx.fillText('ITEM',235,y);
    ctx.textAlign='center';ctx.fillText('QTY',720,y);
    ctx.textAlign='right';ctx.fillText('RATE',875,y);ctx.fillText('AMOUNT',1010,y);
    ctx.textAlign='left';
    y+=28;

    ctx.strokeStyle='#dfe6e9';
    ctx.beginPath();ctx.moveTo(70,y);ctx.lineTo(1010,y);ctx.stroke();
    y+=38;

    const slipImages=await Promise.all(snapshot.items.map(item=>loadImageSafe(item.image||'')));
    ctx.font='400 23px Arial';

    for(let index=0;index<snapshot.items.length;index++){
        const item=snapshot.items[index];
        const image=slipImages[index];
        const rowTop=y-20;
        const imageSize=72;
        const lines=wrapLines(ctx,item.name,420);
        const rowHeight=Math.max(92,lines.length*29+24);

        ctx.fillStyle='#111827';
        ctx.fillText(String(index+1),78,y+16);

        ctx.fillStyle='#f8fafc';
        ctx.fillRect(125,rowTop,imageSize,imageSize);
        ctx.strokeStyle='#e5e7eb';
        ctx.strokeRect(125,rowTop,imageSize,imageSize);

        if(image){
            const scale=Math.min(imageSize/image.naturalWidth,imageSize/image.naturalHeight);
            const drawW=Math.max(1,image.naturalWidth*scale);
            const drawH=Math.max(1,image.naturalHeight*scale);
            const drawX=125+(imageSize-drawW)/2;
            const drawY=rowTop+(imageSize-drawH)/2;
            ctx.drawImage(image,drawX,drawY,drawW,drawH);
        }else{
            ctx.fillStyle='#94a3b8';
            ctx.font='700 15px Arial';
            ctx.textAlign='center';
            ctx.fillText('IMG',125+imageSize/2,rowTop+42);
            ctx.textAlign='left';
            ctx.font='400 23px Arial';
        }

        ctx.fillStyle='#111827';
        lines.forEach((line,lineIndex)=>ctx.fillText(line,235,y+lineIndex*29));

        ctx.textAlign='center';
        ctx.fillText(String(item.qty),720,y+16);

        ctx.textAlign='right';
        ctx.fillText(money(item.price),875,y+16);
        ctx.fillText(money(item.price*item.qty),1010,y+16);
        ctx.textAlign='left';

        y+=rowHeight;
    }

    y+=10;
    ctx.strokeStyle='#dfe6e9';
    ctx.beginPath();ctx.moveTo(520,y);ctx.lineTo(1010,y);ctx.stroke();
    y+=44;

    const summary=[
        ['Items subtotal',order.items_subtotal],
        ['Discount',-Number(order.discount||0)],
        ['Shipping',order.shipping],
        ['Total',order.total]
    ];

    summary.forEach(([label,value],index)=>{
        ctx.font=(index===3?'700 30px Arial':'500 24px Arial');
        ctx.fillStyle=index===3?'#0f7b63':'#374151';
        ctx.fillText(label,600,y);
        ctx.textAlign='right';
        ctx.fillText(money(value),1010,y);
        ctx.textAlign='left';
        y+=42;
    });

    ctx.fillStyle='#111827';
    ctx.font='700 22px Arial';
    ctx.fillText('In Words:',70,y+18);
    ctx.font='400 22px Arial';
    const wordLines=wrapLines(ctx,order.amount_words||amountWords(order.total),800);
    wordLines.forEach((line,i)=>ctx.fillText(line,185,y+18+i*30));
    y+=70+wordLines.length*20;

    if(snapshot.note){
        ctx.font='700 22px Arial';
        ctx.fillText('Order Note:',70,y);
        ctx.font='400 22px Arial';
        const noteLines=wrapLines(ctx,snapshot.note,820);
        noteLines.forEach((line,i)=>ctx.fillText(line,210,y+i*30));
        y+=Math.max(60,noteLines.length*30+20);
    }

    ctx.fillStyle='#f0fdf8';
    ctx.fillRect(70,height-150,940,80);
    ctx.fillStyle='#0f7b63';
    ctx.font='600 22px Arial';
    ctx.fillText('Created with RAR Woo Stock & Order · '+(C.storeName||''),95,height-102);

    const blob=await canvasBlob(canvas);
    return new File([blob],'sales-order-'+String(order.order_num)+'.png',{type:'image/png'});
}

function objectUrlForFile(file){
    if(lastSlipUrl)URL.revokeObjectURL(lastSlipUrl);
    lastSlipUrl=URL.createObjectURL(file);
    return lastSlipUrl;
}

function downloadFile(file){
    const url=objectUrlForFile(file);
    const a=document.createElement('a');
    a.href=url;
    a.download=file.name;
    document.body.appendChild(a);
    a.click();
    a.remove();
    return url;
}

async function shareFile(file,orderNum){
    if(navigator.share&&navigator.canShare?.({files:[file]})){
        await navigator.share({
            title:'Sales Order #'+orderNum,
            text:'Sales Order #'+orderNum,
            files:[file]
        });
        return true;
    }

    downloadFile(file);
    toast('Sharing is not supported here. Slip image downloaded instead.');
    return false;
}

function resetOrderForm(form){
    form.reset();
    $('#rar-shipping').value=C.shipping||0;
    $('#rar-discount-value').value='0';
    $('#rar-discount-type').value='fixed';
    districtInput.value='';
    cityInput.value='';
    cityInput.disabled=true;
    cityInput.placeholder='Select district first';
    items=[];
    renderItems();
}

$('#rar-order-form')?.addEventListener('submit',async event=>{
    event.preventDefault();

    if(!items.length){
        toast('Add at least one product.');
        return;
    }

    const form=event.currentTarget;
    if(!form.reportValidity())return;

    const fd=new FormData(form);
    const phone=normalizeBdPhone(fd.get('phone'));
    if(!phone){
        toast('Enter an 11-digit Bangladesh mobile number after +88, e.g. 01700000000.');
        form.elements.namedItem('phone')?.focus();
        return;
    }

    const district=canonicalFromList(fd.get('district'),C.districts||[]);
    if(!district){
        toast('Select a valid Bangladesh district from the list.');
        districtInput?.focus();
        return;
    }

    const city=canonicalFromList(fd.get('city'),C.cityMap?.[district]||[]);
    if(!city){
        toast('Select a valid Town / City / Upazila for '+district+'.');
        cityInput?.focus();
        return;
    }

    if(!pendingOrderRequestId)pendingOrderRequestId=newRequestId();

    const t=totals();
    const submitMode=event.submitter?.dataset.submitMode||'save';
    const buttons=$$('.rar-order-actions button');
    const snapshot={
        name:String(fd.get('name')||'').trim(),
        phone,
        email:String(fd.get('email')||'').trim(),
        address:String(fd.get('address')||'').trim(),
        district,
        city,
        note:String(fd.get('note')||'').trim(),
        items:items.map(item=>({...item}))
    };

    const payload={
        request_id:pendingOrderRequestId,
        name:snapshot.name,
        phone,
        email:snapshot.email,
        address:snapshot.address,
        city,
        district,
        note:snapshot.note,
        shipping:t.shipping,
        discount_type:t.discountType,
        discount_value:t.discountValue,
        items:items.map(item=>({id:item.id,qty:item.qty,price:item.price}))
    };

    buttons.forEach(button=>{
        button.disabled=true;
        button.dataset.old=button.innerHTML;
        button.innerHTML='<span class="rar-spinner"></span>Saving…';
    });

    try{
        const data=await api('create_order',{payload:JSON.stringify(payload)});
        const result=$('#rar-order-result');

        $('#rar-order-number').textContent='#'+data.order_num;

        result.hidden=false;
        result.classList.remove('error');
        result.innerHTML='<strong>Order #'+esc(data.order_num)+(data.duplicate?' already exists':' created successfully')+'</strong>'+
            '<br>Status: '+esc(data.status)+
            '<br>Total: '+esc(money(data.total))+
            '<br><span class="rar-result-actions"><button type="button" id="rar-slip-download" class="rar-secondary">Download Slip</button>'+
            '<button type="button" id="rar-slip-share" class="rar-secondary">Share Slip</button></span>';

        const slipFile=await buildSlipFile(data,snapshot);
        objectUrlForFile(slipFile);

        const download=$('#rar-slip-download');
        if(download){
            download.onclick=()=>downloadFile(slipFile);
        }

        const share=$('#rar-slip-share');
        if(share)share.onclick=()=>shareFile(slipFile,data.order_num).catch(()=>{});

        if(submitMode==='share'){
            try{
                await shareFile(slipFile,data.order_num);
            }catch(_){
                toast('Order saved. Tap Share Slip to open the phone sharing menu.');
            }
        }

        pendingOrderRequestId='';
        resetOrderForm(form);
        loadStats();
        toast(data.message);
    }catch(error){
        const result=$('#rar-order-result');
        result.hidden=false;
        result.classList.add('error');
        result.textContent=error.message;
        toast(error.message);
    }finally{
        buttons.forEach(button=>{
            button.disabled=false;
            if(button.dataset.old)button.innerHTML=button.dataset.old;
        });
    }
});

function statusOptions(selected,selectedLabel){
    const list=C.orderStatuses||[];
    const current=list.some(status=>status.value===selected)?'':'<option value="'+esc(selected)+'" selected disabled>'+esc(selectedLabel||selected)+'</option>';
    return current+list.map(status=>
        '<option value="'+esc(status.value)+'" '+(status.value===selected?'selected':'')+'>'+esc(status.label)+'</option>'
    ).join('');
}

function managerOrderRow(order){
    const itemText=(order.items||[]).map(item=>esc(item.name)+' × '+esc(item.qty)).join(', ');
    return '<article class="rar-manager-order" data-order-id="'+order.id+'">'+
        '<div class="rar-manager-order-head">'+
            '<div><strong>#'+esc(order.number)+'</strong><span>'+esc(order.date)+'</span></div>'+
            '<b>'+esc(money(order.total))+'</b>'+
        '</div>'+
        '<div class="rar-manager-order-body">'+
            '<div><strong>'+esc(order.customer)+'</strong><span>'+esc(order.phone||'')+'</span><small>'+esc(order.address||'')+'</small></div>'+
            '<p>'+itemText+'</p>'+
        '</div>'+
        '<div class="rar-manager-order-actions">'+
            '<select class="rar-order-status">'+statusOptions(order.status,order.status_label)+'</select>'+
            '<div class="rar-manager-status-buttons"><button type="button" class="rar-primary" data-update-status="'+order.id+'">Update Status</button>'+
            (managerUndo.has(String(order.id))?'<button type="button" class="rar-secondary rar-undo-status" data-undo-status="'+order.id+'">Undo</button>':'')+'</div>'+
        '</div>'+
    '</article>';
}

function renderManagerOrders(){
    const box=$('#rar-manager-orders');
    if(!box)return;

    const q=String($('#rar-orders-search')?.value||'').trim().toLowerCase();
    const list=managerOrders.filter(order=>{
        if(!q)return true;
        return [
            order.number,
            order.customer,
            order.phone,
            order.address,
            order.status_label
        ].some(value=>String(value||'').toLowerCase().includes(q));
    });

    box.innerHTML=list.length
        ? list.map(managerOrderRow).join('')
        : '<div class="rar-empty">No matching orders.</div>';
}

async function loadManagerOrders(mode=managerMode,append=false){
    if(!C.isManager)return;

    const allowed=['all','live','today','processing','completed','returns'];
    const nextMode=allowed.includes(mode)?mode:'all';

    if(!append||nextMode!==managerMode){
        managerPage=1;
        managerOrders=[];
    }

    managerMode=nextMode;
    const box=$('#rar-manager-orders');
    if(box&&!append)box.innerHTML='<div class="rar-empty">Loading orders…</div>';

    const titles={
        all:'All Orders',
        live:'Live Orders',
        today:"Today's Orders",
        processing:'Processing Orders',
        completed:'Completed Orders',
        returns:'Returned / Cancelled Orders'
    };
    $('#rar-orders-title').textContent=titles[managerMode]||'Orders';

    try{
        const data=await api('manager_orders',{mode:managerMode,page:managerPage});
        managerOrders=append?managerOrders.concat(data.orders||[]):(data.orders||[]);
        managerHasMore=Boolean(data.has_more);
        renderManagerOrders();

        const more=$('#rar-orders-more');
        if(more)more.hidden=!managerHasMore;
    }catch(error){
        if(box&&!append)box.innerHTML='<div class="rar-empty">'+esc(error.message)+'</div>';
        toast(error.message);
    }
}

$$('[data-orders-mode]').forEach(button=>{
    button.addEventListener('click',()=>{
        if(!C.isManager)return;
        view('orders');
        loadManagerOrders(button.dataset.ordersMode,false);
    });
});

$('#rar-orders-refresh')?.addEventListener('click',()=>loadManagerOrders(managerMode,false));
$('#rar-orders-more')?.addEventListener('click',()=>{
    if(!managerHasMore)return;
    managerPage++;
    loadManagerOrders(managerMode,true);
});
$('#rar-orders-search')?.addEventListener('input',renderManagerOrders);

$('#rar-manager-orders')?.addEventListener('click',async event=>{
    const undoButton=event.target.closest('[data-undo-status]');
    if(undoButton){
        const id=String(undoButton.dataset.undoStatus);
        const token=managerUndo.get(id);
        if(!token)return;

        const old=undoButton.textContent;
        undoButton.disabled=true;
        undoButton.textContent='Undoing…';

        try{
            const data=await api('undo_order_status',{token});
            managerUndo.delete(id);
            const index=managerOrders.findIndex(order=>String(order.id)===String(data.order.id));
            if(index>=0)managerOrders[index]=data.order;
            renderManagerOrders();
            loadStats(false);
            toast(data.message);
        }catch(error){
            managerUndo.delete(id);
            renderManagerOrders();
            toast(error.message);
        }finally{
            undoButton.disabled=false;
            undoButton.textContent=old;
        }
        return;
    }

    const button=event.target.closest('[data-update-status]');
    if(!button)return;

    const card=button.closest('.rar-manager-order');
    const select=$('.rar-order-status',card);
    const old=button.textContent;
    button.disabled=true;
    button.textContent='Updating…';

    try{
        const data=await api('update_order_status',{
            order_id:button.dataset.updateStatus,
            status:select.value
        });
        const id=String(data.order.id);
        if(data.undo?.token)managerUndo.set(id,data.undo.token);
        else managerUndo.delete(id);

        const index=managerOrders.findIndex(order=>String(order.id)===id);
        if(index>=0)managerOrders[index]=data.order;

        renderManagerOrders();
        loadStats(false);
        toast(data.undo?.token?data.message+' Undo is available for 5 minutes.':data.message);
    }catch(error){
        toast(error.message);
    }finally{
        button.disabled=false;
        button.textContent=old;
    }
});

if('serviceWorker' in navigator){
    addEventListener('load',()=>{
        navigator.serviceWorker.register(C.swUrl,{scope:new URL(C.staffUrl).pathname}).catch(()=>{});
    });
}

calculateTotals();
loadStats();

setInterval(()=>{
    if(!document.hidden&&$('#rar-dashboard')?.classList.contains('active')){
        loadStats(false);
    }
},60000);

document.addEventListener('visibilitychange',()=>{
    if(!document.hidden&&$('#rar-dashboard')?.classList.contains('active')){
        loadStats(false);
    }
});
})();
