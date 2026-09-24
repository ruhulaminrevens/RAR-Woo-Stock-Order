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

const esc=s=>String(s??'').replace(/[&<>'"]/g,m=>({
    '&':'&amp;','<':'&lt;','>':'&gt;',"'":'&#039;','"':'&quot;'
}[m]));

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
    const symbol=String(C.currency||'');
    switch(String(C.currencyPosition||'left')){
        case 'right': return amount+symbol;
        case 'left_space': return symbol+'\u00A0'+amount;
        case 'right_space': return amount+'\u00A0'+symbol;
        default: return symbol+amount;
    }
}

function toast(message){
    const el=$('#rar-toast');
    if(!el)return;
    el.textContent=message;
    el.hidden=false;
    clearTimeout(el._t);
    el._t=setTimeout(()=>{el.hidden=true;},2800);
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

function view(name){
    $$('.rar-view').forEach(v=>v.classList.remove('active'));
    $('#rar-'+name)?.classList.add('active');
    if(name==='stock')loadProducts();
    if(name==='dashboard')loadStats();
    scrollTo({top:0,behavior:'smooth'});
}

$$('[data-view]').forEach(button=>{
    button.onclick=()=>view(button.dataset.view);
});

async function loadStats(){
    try{
        const data=await api('stats');
        $('#stat-orders').textContent=data.orders;
        $('#stat-sales').textContent=money(data.sales);
        $('#stat-low').textContent=data.low_stock;
        $('#stat-out').textContent=data.out_stock;
    }catch(error){
        toast(error.message);
    }
}

function productMeta(product){
    const meta=[];
    if(product.sku)meta.push('SKU: '+esc(product.sku));
    meta.push('Price: '+esc(money(product.price)));
    if(product.regular_price!==null && Number(product.regular_price)>Number(product.price)+0.0001){
        meta.push('Regular: '+esc(money(product.regular_price)));
    }
    meta.push(product.manage_stock?'Stock: '+esc(product.stock_qty):'Stock not managed');
    meta.push(esc(product.stock_status));
    return meta.join(' · ');
}

function productRow(product){
    const managed=Boolean(product.manage_stock);
    const qtyValue=managed?esc(product.stock_qty):'';
    const buttonText=managed?'Save':'Set stock';

    return '<div class="rar-product-row" data-id="'+product.id+'">'+
        '<img src="'+esc(product.image)+'" alt="">'+
        '<div>'+
            '<div class="rar-product-name">'+esc(product.name)+'</div>'+
            '<div class="rar-product-meta">'+productMeta(product)+'</div>'+
        '</div>'+
        '<div class="rar-stock-control">'+
            '<input class="stock-qty" type="number" min="0" step="1" placeholder="—" value="'+qtyValue+'">'+
            '<button data-save="'+product.id+'">'+buttonText+'</button>'+
        '</div>'+
    '</div>';
}

function clientFilter(products,query){
    const q=String(query||'').trim().toLowerCase();
    if(!q)return products;
    return products.filter(product=>
        String(product.name||'').toLowerCase().includes(q) ||
        String(product.sku||'').toLowerCase().includes(q)
    );
}

async function loadProducts(query=''){
    const list=$('#rar-stock-list');
    if(!list)return;

    const requestId=++stockRequest;
    list.innerHTML='<div class="rar-empty">Loading…</div>';

    try{
        const data=await api('products',{search:query});
        if(requestId!==stockRequest)return;

        const filtered=clientFilter(data.items||[],query);
        list.innerHTML=filtered.length
            ? filtered.map(productRow).join('')
            : '<div class="rar-empty">No matching products found.</div>';
    }catch(error){
        if(requestId!==stockRequest)return;
        list.innerHTML='<div class="rar-empty">'+esc(error.message)+'</div>';
    }
}

$('#rar-stock-search')?.addEventListener('input',event=>{
    clearTimeout(stockTimer);
    const query=event.target.value.trim();
    stockTimer=setTimeout(()=>loadProducts(query),280);
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
    save.textContent='…';

    try{
        const data=await api('stock_update',{product_id:save.dataset.save,qty});
        toast(data.message);
        loadProducts($('#rar-stock-search')?.value||'');
    }catch(error){
        toast(error.message);
    }finally{
        save.disabled=false;
        save.textContent=oldText;
    }
});

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
        const stock=product.manage_stock?'Stock '+esc(product.stock_qty):'Stock —';
        return '<div class="rar-search-hit">'+
            '<img src="'+esc(product.image)+'" alt="">'+
            '<div><strong>'+esc(product.name)+'</strong>'+
            '<small>'+(product.sku?'SKU '+esc(product.sku)+' · ':'')+esc(money(product.price))+' · '+stock+'</small></div>'+
            '<button type="button" data-id="'+product.id+'">Add</button>'+
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
            const data=await api('products',{search:query});
            if(requestId!==orderRequest)return;
            showOrderSearch(data.items,query);
        }catch(error){
            if(requestId!==orderRequest)return;
            toast(error.message);
        }
    },250);
});

$('#rar-order-search-results')?.addEventListener('click',event=>{
    const button=event.target.closest('[data-id]');
    if(!button)return;

    const box=$('#rar-order-search-results');
    const product=box._data?.find(x=>String(x.id)===button.dataset.id);
    if(!product)return;

    const existing=items.find(item=>item.id===product.id);
    if(existing){
        existing.qty++;
    }else{
        items.push({id:product.id,name:product.name,qty:1,price:Number(product.price)});
    }

    invalidateOrderRequest();
    renderItems();
    $('#rar-order-search').value='';
    box.classList.remove('open');
});

function renderItems(){
    const box=$('#rar-order-items');
    box.innerHTML=items.map((item,index)=>
        '<div class="rar-order-item" data-index="'+index+'">'+
            '<div class="title">'+esc(item.name)+'</div>'+
            '<input class="item-qty" type="number" min="1" step="1" value="'+item.qty+'">'+
            '<input class="item-price" type="number" min="0" step=".01" value="'+Number(item.price).toFixed(2)+'" '+(C.allowPrice?'':'readonly')+'>'+
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
    if(event.target.classList.contains('item-qty')){
        items[index].qty=Math.max(1,parseInt(event.target.value||1,10));
    }
    if(event.target.classList.contains('item-price')&&C.allowPrice){
        items[index].price=Math.max(0,Number(event.target.value)||0);
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

$('#rar-shipping')?.addEventListener('input',()=>{
    invalidateOrderRequest();
    calculateTotals();
});

$('#rar-order-form')?.addEventListener('input',event=>{
    if(event.target.id==='rar-order-search')return;
    invalidateOrderRequest();
});

function calculateTotals(){
    const subtotal=items.reduce((sum,item)=>sum+item.price*item.qty,0);
    const shipping=Number($('#rar-shipping')?.value||0);
    $('#rar-subtotal').textContent=money(subtotal);
    $('#rar-total').textContent=money(subtotal+shipping);
}

function canonicalDistrict(value){
    const input=String(value||'').trim();
    if(!input)return '';
    const districts=Array.isArray(C.districts)?C.districts:[];
    return districts.find(name=>String(name).toLowerCase()===input.toLowerCase())||'';
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
    const district=canonicalDistrict(fd.get('district'));

    if(!district){
        const input=form.elements.namedItem('district');
        toast('Select a valid Bangladesh district.');
        input?.focus();
        return;
    }

    if(!pendingOrderRequestId){
        pendingOrderRequestId=newRequestId();
    }

    const button=$('.rar-save-order',form);
    const payload={
        request_id:pendingOrderRequestId,
        name:fd.get('name'),
        phone:fd.get('phone'),
        email:fd.get('email'),
        address:fd.get('address'),
        city:fd.get('city'),
        district,
        note:fd.get('note'),
        shipping:Number($('#rar-shipping').value)||0,
        items:items.map(item=>({id:item.id,qty:item.qty,price:item.price}))
    };

    const oldHtml=button.innerHTML;
    button.disabled=true;
    button.innerHTML='<span class="rar-spinner"></span>Saving order…';

    try{
        const data=await api('create_order',{payload:JSON.stringify(payload)});
        const result=$('#rar-order-result');
        const title=data.duplicate
            ? 'Order #'+esc(data.order_num)+' already exists'
            : 'Order #'+esc(data.order_num)+' created';
        const adminLink=data.admin_url
            ? '<br><a href="'+esc(data.admin_url)+'" target="_blank" rel="noopener">Open in WooCommerce</a>'
            : '';

        result.hidden=false;
        result.classList.remove('error');
        result.innerHTML='<strong>'+title+'</strong>'+
            '<br>Status: '+esc(data.status)+
            '<br>Total: '+esc(data.total)+
            adminLink;

        pendingOrderRequestId='';
        form.reset();
        $('#rar-shipping').value=C.shipping||0;
        items=[];
        renderItems();
        loadStats();
        toast(data.message);
    }catch(error){
        const result=$('#rar-order-result');
        result.hidden=false;
        result.classList.add('error');
        result.textContent=error.message;
        toast(error.message);
    }finally{
        button.disabled=false;
        button.innerHTML=oldHtml;
    }
});

if('serviceWorker' in navigator){
    addEventListener('load',()=>{
        navigator.serviceWorker.register(C.swUrl,{scope:new URL(C.staffUrl).pathname}).catch(()=>{});
    });
}

loadStats();
})();
