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
let managerMode='all';
let managerOrders=[];
let lastSlipUrl='';

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

    if(!response.ok||!json.success){
        throw new Error(json?.data?.message||'Request failed.');
    }

    return json.data;
}

function updateClock(){
    const el=$('#rar-live-clock');
    const orderDate=$('#rar-order-date');
    const now=new Date();
    const tz=C.siteTimezone||undefined;

    try{
        const weekday=new Intl.DateTimeFormat('en-US',{weekday:'long',timeZone:tz}).format(now);
        const date=new Intl.DateTimeFormat('en-US',{month:'short',day:'2-digit',year:'numeric',timeZone:tz}).format(now);
        const time=new Intl.DateTimeFormat('en-US',{hour:'2-digit',minute:'2-digit',second:'2-digit',hour12:true,timeZone:tz}).format(now);
        if(el)el.textContent=weekday+' । '+date+' । '+time;
        if(orderDate)orderDate.textContent=date;
    }catch(_){
        if(el)el.textContent=now.toLocaleString();
        if(orderDate)orderDate.textContent=now.toLocaleDateString();
    }
}

updateClock();
setInterval(updateClock,1000);

function view(name){
    $$('.rar-view').forEach(v=>v.classList.remove('active'));
    $('#rar-'+name)?.classList.add('active');

    if(name==='stock')loadProducts('',false);
    if(name==='dashboard')loadStats();

    window.scrollTo({top:0,behavior:'smooth'});
}

$$('[data-view]').forEach(button=>{
    button.addEventListener('click',()=>view(button.dataset.view));
});

function renderAnalytics(data){
    if(!C.isManager||!data)return;

    const week=$('#rar-week-sales');
    const growth=$('#rar-growth');
    const chart=$('#rar-sales-chart');

    if(week)week.textContent=money(data.week_total||0);

    if(growth){
        const g=Number(data.growth_pct||0);
        growth.textContent=(g>0?'+':'')+g.toFixed(1)+'% vs previous week';
        growth.classList.toggle('negative',g<0);
        growth.classList.toggle('positive',g>=0);
    }

    if(chart){
        const values=(data.sales||[]).map(Number);
        const max=Math.max(1,...values);
        chart.innerHTML=values.map((value,i)=>{
            const height=Math.max(4,(value/max)*100);
            return '<div class="rar-bar-col">'+
                '<span class="rar-bar-value">'+esc(money(value))+'</span>'+
                '<div class="rar-bar-track"><i style="height:'+height+'%"></i></div>'+
                '<small>'+esc((data.labels||[])[i]||'')+'</small>'+
            '</div>';
        }).join('');
    }
}

async function loadStats(){
    try{
        const data=await api('stats');
        $('#stat-orders').textContent=data.today_orders;
        $('#stat-sales').textContent=money(data.today_sales);
        $('#stat-completed').textContent=data.completed_orders;
        $('#stat-returned').textContent=data.returned_cancelled;
        $('#stat-all-stock').textContent=data.all_stock;
        $('#stat-available').textContent=data.available_stock;
        $('#stat-out').textContent=data.out_stock;

        $('#stock-count-all').textContent=data.all_stock;
        $('#stock-count-high').textContent=data.high_stock;
        $('#stock-count-low').textContent=data.low_stock;
        $('#stock-count-out').textContent=data.out_stock;
        $('#stock-count-unmanaged').textContent=data.unmanaged_stock;

        renderAnalytics(data.analytics);
    }catch(error){
        toast(error.message);
    }
}

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
            '<button data-save="'+product.id+'" type="button">'+buttonText+'</button>'+
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

$('#rar-stock-list')?.addEventListener('click',async event=>{
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

    $('#rar-item-count').textContent=items.length;
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

async function buildSlipFile(order,snapshot){
    const height=Math.max(1450,1120+snapshot.items.length*82);
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
    ctx.fillText('ITEM',130,y);
    ctx.textAlign='center';ctx.fillText('QTY',720,y);
    ctx.textAlign='right';ctx.fillText('RATE',875,y);ctx.fillText('AMOUNT',1010,y);
    ctx.textAlign='left';
    y+=28;

    ctx.strokeStyle='#dfe6e9';
    ctx.beginPath();ctx.moveTo(70,y);ctx.lineTo(1010,y);ctx.stroke();
    y+=36;

    ctx.font='400 23px Arial';
    snapshot.items.forEach((item,index)=>{
        const lines=wrapLines(ctx,item.name,500);
        ctx.fillText(String(index+1),78,y);
        lines.forEach((line,lineIndex)=>ctx.fillText(line,130,y+lineIndex*29));
        ctx.textAlign='center';ctx.fillText(String(item.qty),720,y);
        ctx.textAlign='right';ctx.fillText(money(item.price),875,y);
        ctx.fillText(money(item.price*item.qty),1010,y);
        ctx.textAlign='left';
        y+=Math.max(58,lines.length*29+20);
    });

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

function downloadFile(file){
    if(lastSlipUrl)URL.revokeObjectURL(lastSlipUrl);
    lastSlipUrl=URL.createObjectURL(file);
    const a=document.createElement('a');
    a.href=lastSlipUrl;
    a.download=file.name;
    document.body.appendChild(a);
    a.click();
    a.remove();
    return lastSlipUrl;
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
        toast('Enter a valid Bangladesh mobile number, e.g. +8801XXXXXXXXX.');
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
        const url=downloadFile(slipFile);

        const download=$('#rar-slip-download');
        if(download){
            download.onclick=()=>{
                const a=document.createElement('a');
                a.href=url;
                a.download=slipFile.name;
                a.click();
            };
        }

        const share=$('#rar-slip-share');
        if(share)share.onclick=()=>shareFile(slipFile,data.order_num).catch(()=>{});

        if(submitMode==='share'){
            try{await shareFile(slipFile,data.order_num);}catch(_){}
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

function statusOptions(selected){
    return (C.orderStatuses||[]).map(status=>
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
            '<select class="rar-order-status">'+statusOptions(order.status)+'</select>'+
            '<button type="button" class="rar-primary" data-update-status="'+order.id+'">Update Status</button>'+
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

async function loadManagerOrders(mode=managerMode){
    if(!C.isManager)return;

    managerMode=mode==='live'?'live':'all';
    const box=$('#rar-manager-orders');
    if(box)box.innerHTML='<div class="rar-empty">Loading orders…</div>';
    $('#rar-orders-title').textContent=managerMode==='live'?'Live Orders':'All Orders';

    try{
        const data=await api('manager_orders',{mode:managerMode});
        managerOrders=data.orders||[];
        renderManagerOrders();
    }catch(error){
        if(box)box.innerHTML='<div class="rar-empty">'+esc(error.message)+'</div>';
        toast(error.message);
    }
}

$$('[data-orders-mode]').forEach(button=>{
    button.addEventListener('click',()=>{
        view('orders');
        loadManagerOrders(button.dataset.ordersMode);
    });
});

$('#rar-orders-refresh')?.addEventListener('click',()=>loadManagerOrders());
$('#rar-orders-search')?.addEventListener('input',renderManagerOrders);

$('#rar-manager-orders')?.addEventListener('click',async event=>{
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
        toast(data.message);
        const index=managerOrders.findIndex(order=>String(order.id)===String(data.order.id));
        if(index>=0)managerOrders[index]=data.order;

        if(managerMode==='live'){
            const closed=['completed','cancelled','refunded','failed','returned'];
            managerOrders=managerOrders.filter(order=>!closed.includes(order.status));
        }

        renderManagerOrders();
        loadStats();
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
})();
