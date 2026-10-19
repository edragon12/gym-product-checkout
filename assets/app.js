(() => {
  const root = document.getElementById('csw-store-app');
  if (!root || !window.CSW_STORE) return;
  const paymentKey = 'csw_pending_payment';
  const buyerNameKey = 'csw_buyer_name';
  const readPendingPayment = () => { try { return JSON.parse(sessionStorage.getItem(paymentKey) || 'null'); } catch(e) { return null; } };
  const savePendingPayment = payment => { try { sessionStorage.setItem(paymentKey, JSON.stringify(payment)); } catch(e) {} };
  const clearPendingPayment = () => { try { sessionStorage.removeItem(paymentKey); } catch(e) {} };
  const readSavedBuyerName = () => { try { return localStorage.getItem(buyerNameKey) || ''; } catch(e) { return ''; } };
  const saveBuyerName = name => { try { localStorage.setItem(buyerNameKey,name); } catch(e) {} };
  const clearSavedBuyerName = () => { try { localStorage.removeItem(buyerNameKey); } catch(e) {} };
  const savedBuyerName = readSavedBuyerName();
  let data = null, cart = {}, active = 'checkout', busy = false, selectedVenmo = null, search = '', pendingPayment = readPendingPayment(), buyerName = savedBuyerName, rememberBuyer = Boolean(savedBuyerName), discountCode = '', discountApplied = false, validatedDiscountCode = '';
  const money = cents => new Intl.NumberFormat('en-US',{style:'currency',currency:'USD'}).format(cents/100);
  const api = async (path, options={}) => {
    const response = await fetch(CSW_STORE.api + path,{...options,headers:{'Content-Type':'application/json','X-WP-Nonce':CSW_STORE.nonce,...(options.headers||{})}});
    const payload = await response.json();
    if(!response.ok) throw new Error(payload.message || 'Something went wrong.');
    return payload;
  };
  const load = async (showPending=true) => { try { data = await api(CSW_STORE.canManage?'/data':'/catalog'); selectedVenmo = selectedVenmo || data.settings.venmoUsernames[0] || ''; render(); if(showPending && pendingPayment) completionModal(); } catch(e) { root.innerHTML=`<div class="csw-error">${escapeHtml(e.message)} <button data-action="retry">Try again</button></div>`; } };
  const escapeHtml = value => String(value).replace(/[&<>'"]/g,c=>({'&':'&amp;','<':'&lt;','>':'&gt;',"'":'&#39;','"':'&quot;'}[c]));
  const lines = () => data.products.filter(p=>cart[p.id]).map(p=>({...p,quantity:cart[p.id],lineTotal:p.priceCents*cart[p.id]}));
  const total = () => { const sub=lines().reduce((s,l)=>s+l.lineTotal,0);const discount=discountApplied?Math.round(sub*(data.settings.coachDiscountPercent/100)):0;const discounted=Math.max(0,sub-discount);const tax=Math.round(discounted*(data.settings.taxRate/100));return {sub,discount,tax,total:discounted+tax}; };
  const icon = name => ({checkout:'◫',inventory:'▦',sales:'↻'}[name]||'');
  const cleanTheme = () => {
    document.body.classList.add('csw-store-page');
    document.querySelectorAll('main h1,main h2,.wp-block-post-title,.entry-title').forEach(el=>{if(el.textContent.trim().toLowerCase()==='hello world!')el.remove();});
    document.querySelectorAll('main p,.wp-block-post-content p').forEach(el=>{if(el.textContent.trim()==='Welcome to WordPress. This is your first post. Edit or delete it, then start writing!')el.remove();});
  };
  function render(){
    const counts=total();
    const cartCount=lines().reduce((sum,item)=>sum+item.quantity,0);
    const showMobilePay=active==='checkout'&&cartCount>0;
    document.body.classList.toggle('csw-cart-active',showMobilePay);
    root.innerHTML=`<div class="csw-shell">
      <header class="csw-header"><div class="csw-brand"><span class="csw-mark">↔</span><div><small>GYM STORE</small><strong>${escapeHtml(data.settings.gymName||'Your Gym')}</strong></div></div><span class="csw-venmo">${selectedVenmo?`◉ ${escapeHtml(selectedVenmo)}`:'Venmo setup needed'}</span></header>
      ${data.settings.testMode?'<div class="csw-test-banner"><strong>TEST MODE</strong> No money will be sent to Venmo.</div>':''}
      ${CSW_STORE.canManage?`<nav class="csw-tabs">${['checkout','sales'].map(t=>`<button class="${active===t?'is-active':''}" data-tab="${t}">${icon(t)} ${t[0].toUpperCase()+t.slice(1)}</button>`).join('')}</nav>`:'<div class="csw-member-note">Member self-checkout · Pay securely with Venmo</div>'}
      ${pendingPayment?`<div class="csw-payment-reminder"><span><strong>Checkout #${pendingPayment.id} is awaiting your confirmation.</strong><small>${money(pendingPayment.totalCents)} to ${escapeHtml(pendingPayment.venmoUsername)}</small></span><button data-action="resume-payment">Finish checkout</button></div>`:''}
      <div class="csw-content">${active==='checkout'?checkoutView(counts):salesView()}</div>
      <footer class="csw-contact">Questions or problems? <a href="mailto:${escapeHtml(data.settings.contactEmail)}">Contact us</a></footer>
    </div>${showMobilePay?`<button class="csw-mobile-pay" data-action="pay"><span><b>${cartCount} item${cartCount===1?'':'s'}</b><small>${money(counts.total)}</small></span><strong>Pay with Venmo <em>›</em></strong></button>`:''}<div class="csw-toast" aria-live="polite"></div>`;
  }
  function checkoutView(counts){ const term=search.trim().toLowerCase();const products=data.products.filter(p=>p.name.toLowerCase().includes(term));return `<div class="csw-checkout"><section><div class="csw-title"><div><small>QUICK CHECKOUT</small><h2>Choose an item</h2></div><span>${term?`${products.length} match${products.length===1?'':'es'}`:'All items active'}</span></div><div class="csw-search"><span aria-hidden="true">⌕</span><input type="search" data-product-search value="${escapeHtml(search)}" placeholder="Search products" aria-label="Search products" autocomplete="off"><button data-action="clear-search" aria-label="Clear product search" ${search?'':'hidden'}>×</button></div>${products.length?`<div class="csw-grid">${products.map(p=>productCard(p,data.products.indexOf(p))).join('')}</div>`:`<div class="csw-no-results"><strong>No products found</strong><small>Try a different search.</small></div>`}</section>
    <aside class="csw-cart"><div><small>CURRENT SALE</small><h2>Cart</h2></div><div class="csw-lines">${lines().length?lines().map(l=>`<div class="csw-line"><b>${l.quantity}</b><span><strong>${escapeHtml(l.name)}</strong><small>${money(l.priceCents)} each</small></span><em>${money(l.lineTotal)}</em><button data-remove="${l.id}" aria-label="Remove ${escapeHtml(l.name)}">×</button></div>`).join(''):'<div class="csw-empty">Tap an item to begin</div>'}</div><div class="csw-totals"><p><span>Subtotal</span><span>${money(counts.sub)}</span></p>${counts.discount?`<p class="csw-discount-line"><span>Coach discount (30%)</span><span>−${money(counts.discount)}</span></p>`:''}${counts.tax?`<p><span>Tax</span><span>${money(counts.tax)}</span></p>`:''}<strong><span>Total</span><span>${money(counts.total)}</span></strong></div><button class="csw-pay" data-action="pay" ${!lines().length?'disabled':''}>Pay with Venmo <span>›</span></button></aside></div>`; }
  function productIcon(name){const n=name.toLowerCase();if(n.includes('ice shaker'))return '❄';if(n.includes('drop-in'))return '🏋';if(n.includes('weekly'))return '∞';if(n.includes('lmnt'))return '◇';if(n.includes('mte'))return '⚡';if(n.includes('perfect'))return '▰';if(n.includes('rip fix'))return '✚';if(n.includes('t-shirt'))return 'T';if(n.includes('water'))return '💧';return '↔';}
  function productCard(p,i){ const q=cart[p.id]||0;const visual=p.imageUrl?`<img src="${escapeHtml(p.imageUrl)}" alt="${escapeHtml(p.name)}" loading="lazy" decoding="async">`:''; return `<article class="csw-product csw-color-${i%4}"><div class="csw-product-top"><small>${String(i+1).padStart(2,'0')}</small><span>Active</span></div><div class="csw-product-visual ${p.imageUrl?'has-image':'is-placeholder'}"><span aria-hidden="true">${productIcon(p.name)}</span>${visual}</div><div class="csw-product-info"><h3>${escapeHtml(p.name)}</h3><b>${money(p.priceCents)}</b></div>${q?`<div class="csw-qty"><button data-product="${p.id}" data-delta="-1" aria-label="Remove one ${escapeHtml(p.name)}">−</button><strong>${q}</strong><button data-product="${p.id}" data-delta="1" aria-label="Add one ${escapeHtml(p.name)}">+</button></div>`:`<button class="csw-add" data-product="${p.id}" data-delta="1">Add <b>+</b></button>`}</article>`; }
  function monthlyStatsView(){const stats=data.monthlyStats||{label:'This month',totalCents:0,paidSales:0,bestSellers:[]};const sellers=stats.bestSellers||[];return `<div class="csw-monthly-dashboard"><article class="csw-month-total"><small>${escapeHtml(stats.label)}</small><strong>${money(stats.totalCents)}</strong><span>Collected from ${stats.paidSales} paid sale${stats.paidSales===1?'':'s'}</span></article><article class="csw-best-sellers"><div><small>BEST SELLERS</small><strong>Top items this month</strong></div>${sellers.length?`<ol>${sellers.map(item=>`<li><span>${escapeHtml(item.name)}</span><b>${item.quantity} sold</b></li>`).join('')}</ol>`:'<p>No confirmed sales this month yet.</p>'}</article></div>`;}
  function salesView(){ return `<section class="csw-surface"><div class="csw-title"><div><small>VENMO PAYMENTS</small><h2>Recent sales</h2></div><button class="csw-refresh" data-action="refresh">↻ Refresh</button></div>${monthlyStatsView()}${data.sales.length?`<div class="csw-sales">${data.sales.map(s=>{const pending=s.paymentStatus==='pending',submitted=s.paymentStatus==='submitted',refunded=s.paymentStatus==='refunded';const label=pending?'Unpaid':submitted?'Customer submitted':refunded?'Refunded':'Payment received ✓';return `<div class="${pending?'is-pending':submitted?'is-submitted':refunded?'is-refunded':''}"><i>${pending?'!':submitted?'⌛':refunded?'↩':'✓'}</i><span><strong>${label} — Sale #${s.id} ${s.isTest?'<em class="csw-test-tag">TEST</em>':''}</strong><span class="csw-buyer-name">Buyer: ${escapeHtml(s.buyerName||'Not provided')}</span><small>${escapeHtml(s.createdAt)} · ${s.itemCount} item${s.itemCount===1?'':'s'} · ${escapeHtml(s.venmoUsername)}</small>${submitted?'<small class="csw-submitted-note">Athlete says the Venmo payment is complete. Verify it in Venmo before confirming.</small>':''}<span class="csw-sale-items">${(s.items||[]).map(item=>`<span>${item.quantity} × ${escapeHtml(item.name)} <small>${money(item.lineTotalCents)}</small></span>`).join('')||'No item details recorded'}</span>${s.discountCents?`<span class="csw-sale-discount">Coach discount (${s.discountPercent}%): −${money(s.discountCents)}</span>`:''}</span><b>${money(s.totalCents)}</b><div class="csw-sale-actions">${pending||submitted?`<button class="csw-confirm-order" data-confirm-order="${s.id}">Payment received ✓</button>`:''}${!refunded?`<button data-edit-sale="${s.id}">Edit</button>`:''}${!pending&&!submitted&&!refunded?`<button data-refund-sale="${s.id}">Refund</button>`:''}<button class="csw-delete" data-delete-sale="${s.id}">Delete</button></div></div>`;}).join('')}</div>`:'<div class="csw-empty csw-empty-large">No sales yet<br><small>Sales will appear here as unpaid until confirmed.</small></div>'}</section>`; }
  function editModal(sale){root.insertAdjacentHTML('beforeend',`<div class="csw-overlay"><div class="csw-modal"><header><span class="csw-wallet">✎</span><small>EDIT TRANSACTION</small><h2>Sale #${sale.id}</h2><p>Change item quantities. The total will be recalculated.</p></header><main><div class="csw-edit-items">${sale.items.map(item=>`<label><span><strong>${escapeHtml(item.name)}</strong><small>${money(item.unitPriceCents)} each</small></span><input type="number" min="1" step="1" value="${item.quantity}" data-edit-item="${item.id}"></label>`).join('')}</div></main><footer><button data-action="cancel">Cancel</button><button class="csw-confirm" data-action="save-edit" data-sale-id="${sale.id}">Save changes</button></footer></div></div>`);}
  function modal(){ const t=total(),test=data.settings.testMode; root.insertAdjacentHTML('beforeend',`<div class="csw-overlay"><div class="csw-modal csw-checkout-modal"><header><span class="csw-wallet">${test?'T':'▣'}</span><small>${test?'TEST MODE':'VENMO PAYMENT'}</small><h2>${test?'Test ': 'Pay '}${money(t.total)}</h2><p>${test?'No money will be transferred.':`Payment will be sent to ${escapeHtml(selectedVenmo)}.`}</p></header><main><div class="csw-buyer-fields"><label><span>Buyer name <b>Required</b></span><input type="text" data-buyer-name value="${escapeHtml(buyerName)}" placeholder="First and last name" autocomplete="name" maxlength="190"></label><label class="csw-remember-name"><input type="checkbox" data-remember-buyer ${rememberBuyer?'checked':''}><span><strong>Remember my name on this phone</strong><small>Use only on your personal phone.</small></span></label><label><span>Coach discount code <small>Optional</small></span><div class="csw-code-row"><input type="text" data-discount-code value="${escapeHtml(discountCode)}" placeholder="Enter code" autocomplete="off" maxlength="50"><button data-action="apply-discount">${discountApplied?'Applied ✓':'Apply'}</button></div></label></div>${discountApplied?`<div class="csw-discount-applied"><strong>30% coach discount applied</strong><span>−${money(t.discount)}</span></div>`:''}<div class="csw-modal-total"><span>Total</span><strong>${money(t.total)}</strong></div><p>${test?'This creates a clearly labeled test transaction.':'After finishing in Venmo, return to this page and tap <strong>I completed my payment</strong>. The sale will then wait for staff verification.'}</p></main><footer><button data-action="cancel">Cancel</button><button class="csw-confirm" data-action="confirm">${test?'Start test':'Continue to Venmo ↗'}</button></footer></div></div>`); }
  function completionModal(){
    if(!pendingPayment || root.querySelector('.csw-overlay')) return;
    const itemText=(pendingPayment.items||[]).map(item=>`${item.quantity} × ${escapeHtml(item.name)}`).join('<br>');
    root.insertAdjacentHTML('beforeend',`<div class="csw-overlay"><div class="csw-modal csw-completion-modal"><header><span class="csw-wallet">${pendingPayment.testMode?'T':'✓'}</span><small>${pendingPayment.testMode?'TEST MODE':'VENMO PAYMENT'}</small><h2>${pendingPayment.testMode?'Simulate payment':'Did you finish in Venmo?'}</h2><p>Checkout #${pendingPayment.id} · ${money(pendingPayment.totalCents)}</p></header><main>${pendingPayment.testMode?'<div class="csw-test-callout"><strong>No real payment was sent.</strong><br>This button tests the customer-confirmation step.</div>':''}<p><strong>Buyer:</strong> ${escapeHtml(pendingPayment.buyerName)}</p><p class="csw-order-summary"><strong>Order</strong><br>${itemText}</p>${pendingPayment.discountCents?`<div class="csw-discount-applied"><strong>Coach discount (30%)</strong><span>−${money(pendingPayment.discountCents)}</span></div>`:''}<p>Only continue after the Venmo transaction is complete. Staff will verify the payment before marking it received.</p></main><footer><button data-action="cancel">Not yet</button><button class="csw-confirm" data-action="payment-complete">${pendingPayment.testMode?'Simulate completed payment':'I completed my payment'}</button></footer></div></div>`);
  }
  function receiptModal(payment,result){
    const confirmed=result.paymentStatus==='confirmed';
    root.insertAdjacentHTML('beforeend',`<div class="csw-overlay"><div class="csw-modal csw-receipt-modal"><header><span class="csw-wallet">✓</span><small>${payment.testMode?'TEST CONFIRMATION':'ORDER CONFIRMATION'}</small><h2>${confirmed?'Payment received':'Thank you, '+escapeHtml(payment.buyerName)+'!'}</h2><p>Sale #${payment.id} · ${money(payment.totalCents)}</p></header><main><div class="csw-success-message"><strong>${confirmed?'Your payment has been verified.':'Your payment was submitted for verification.'}</strong><p>${confirmed?'You are all set.':'Staff will check Venmo and mark the sale paid.'}</p></div>${payment.discountCents?`<div class="csw-discount-applied"><strong>Coach discount (30%)</strong><span>−${money(payment.discountCents)}</span></div>`:''}${payment.testMode?'<div class="csw-test-callout"><strong>Test only:</strong> no money was transferred.</div>':''}</main><footer><span></span><button class="csw-confirm" data-action="receipt-done">Done</button></footer></div></div>`);
  }
  const purchaseNote = () => lines().map(item=>`${item.quantity} x ${item.name}`).join(', ');
  const venmoUrl = (amount, note) => `https://venmo.com/?txn=pay&recipients=${encodeURIComponent(selectedVenmo.replace(/^@/,''))}&amount=${(amount/100).toFixed(2)}&note=${encodeURIComponent(note)}`;
  const toast = message => { const el=document.querySelector('.csw-toast'); if(el){el.textContent=message;el.classList.add('show');setTimeout(()=>el.classList.remove('show'),3000);} };
  root.addEventListener('click',async e=>{ const button=e.target.closest('button,[data-tab]'); if(!button||busy)return;
    if(button.dataset.tab){active=button.dataset.tab;render();return;}
    if(button.dataset.product){const p=data.products.find(x=>x.id===Number(button.dataset.product));const next=Math.max(0,(cart[p.id]||0)+Number(button.dataset.delta)); if(next)cart[p.id]=next;else delete cart[p.id];render();return;}
    if(button.dataset.remove){delete cart[button.dataset.remove];render();return;}
    if(button.dataset.action==='retry'||button.dataset.action==='refresh'){await load();return;}
    if(button.dataset.action==='clear-search'){search='';render();root.querySelector('[data-product-search]')?.focus();return;}
    if(button.dataset.action==='pay'){if(!data.settings.testMode&&!selectedVenmo){toast('Checkout is not available until the Venmo account is configured.');return;}modal();return;}
    if(button.dataset.action==='resume-payment'){completionModal();return;}
    if(button.dataset.action==='cancel'){document.querySelector('.csw-overlay')?.remove();return;}
    if(button.dataset.action==='receipt-done'){document.querySelector('.csw-overlay')?.remove();return;}
    if(button.dataset.action==='apply-discount'){
      buyerName=(root.querySelector('[data-buyer-name]')?.value||buyerName).trim();
      rememberBuyer=root.querySelector('[data-remember-buyer]')?.checked||false;
      discountCode=(root.querySelector('[data-discount-code]')?.value||'').trim();
      if(!discountCode){discountApplied=false;validatedDiscountCode='';toast('Enter the coach discount code.');return;}
      busy=true;button.textContent='Checking…';
      try{const result=await api('/validate-discount',{method:'POST',body:JSON.stringify({code:discountCode})});if(!result.valid){discountApplied=false;validatedDiscountCode='';toast(result.message);button.textContent='Apply';busy=false;return;}discountApplied=true;validatedDiscountCode=discountCode.toUpperCase().replace(/[^A-Z0-9_-]/g,'');busy=false;document.querySelector('.csw-overlay')?.remove();render();modal();toast('30% coach discount applied.');}
      catch(err){toast(err.message);button.textContent='Apply';busy=false;}
      return;
    }
    if(button.dataset.action==='payment-complete'){
      const payment=pendingPayment;if(!payment)return;
      busy=true;button.textContent=payment.testMode?'Simulating…':'Submitting…';
      try{const result=await api('/submit-payment',{method:'POST',body:JSON.stringify({sale_id:payment.id,confirmation_token:payment.confirmationToken})});pendingPayment=null;clearPendingPayment();cart={};buyerName=rememberBuyer?readSavedBuyerName():'';discountCode='';discountApplied=false;validatedDiscountCode='';document.querySelector('.csw-overlay')?.remove();busy=false;await load(false);receiptModal(payment,result);}
      catch(err){toast(err.message);button.textContent=payment.testMode?'Simulate completed payment':'I completed my payment';busy=false;}
      return;
    }
    if(button.dataset.confirmOrder){busy=true;button.textContent='Confirming…';try{await api('/confirm',{method:'POST',body:JSON.stringify({sale_id:Number(button.dataset.confirmOrder)})});await load();toast('Payment confirmed.');}catch(err){toast(err.message);button.textContent='Confirm payment';}finally{busy=false}return;}
    if(button.dataset.editSale){const sale=data.sales.find(s=>s.id===Number(button.dataset.editSale));if(sale)editModal(sale);return;}
    if(button.dataset.action==='save-edit'){const saleId=Number(button.dataset.saleId);const items=[...root.querySelectorAll('[data-edit-item]')].map(input=>({id:Number(input.dataset.editItem),quantity:Number(input.value)}));if(items.some(item=>!Number.isInteger(item.quantity)||item.quantity<1)){toast('Each quantity must be at least 1.');return;}busy=true;button.textContent='Saving…';try{await api('/edit-sale',{method:'POST',body:JSON.stringify({sale_id:saleId,items})});document.querySelector('.csw-overlay')?.remove();await load();toast('Transaction updated.');}catch(err){toast(err.message);button.textContent='Save changes';}finally{busy=false}return;}
    if(button.dataset.deleteSale){const saleId=Number(button.dataset.deleteSale);if(!window.confirm(`Permanently delete Sale #${saleId}? This cannot be undone.`))return;busy=true;try{await api('/delete-sale',{method:'POST',body:JSON.stringify({sale_id:saleId})});await load();toast('Transaction deleted.');}catch(err){toast(err.message)}finally{busy=false}return;}
    if(button.dataset.refundSale){const saleId=Number(button.dataset.refundSale);if(!window.confirm(`Mark Sale #${saleId} as refunded? Complete the money return separately in Venmo.`))return;busy=true;try{await api('/refund-sale',{method:'POST',body:JSON.stringify({sale_id:saleId})});await load();toast('Transaction marked refunded.');}catch(err){toast(err.message)}finally{busy=false}return;}
    if(button.dataset.action==='confirm'){
      buyerName=(root.querySelector('[data-buyer-name]')?.value||buyerName).trim();
      rememberBuyer=root.querySelector('[data-remember-buyer]')?.checked||false;
      discountCode=(root.querySelector('[data-discount-code]')?.value||discountCode).trim();
      if(buyerName.length<2){toast('Please enter the buyer name.');root.querySelector('[data-buyer-name]')?.focus();return;}
      const normalizedCode=discountCode.toUpperCase().replace(/[^A-Z0-9_-]/g,'');
      if(discountCode&&(!discountApplied||normalizedCode!==validatedDiscountCode)){toast('Tap Apply to verify the coach discount code.');root.querySelector('[data-discount-code]')?.focus();return;}
      busy=true;
      button.textContent=data.settings.testMode?'Creating test order…':'Opening Venmo…';
      const venmoWindow=data.settings.testMode?null:window.open('','_blank');
      if(venmoWindow){
        try{venmoWindow.document.title='Opening Venmo';venmoWindow.document.body.innerHTML='<p style="font:18px Arial;padding:28px">Opening Venmo…</p>';}catch(err){}
      }
      try{
        const t=total(),note=purchaseNote(),orderItems=lines().map(l=>({productId:l.id,quantity:l.quantity,name:l.name}));
        const response=await api('/order',{method:'POST',body:JSON.stringify({items:orderItems.map(l=>({productId:l.productId,quantity:l.quantity})),venmoUsername:selectedVenmo,buyerName,discountCode:discountApplied?discountCode:''})});
        if(rememberBuyer)saveBuyerName(response.order.buyerName);else clearSavedBuyerName();
        pendingPayment={id:response.order.id,buyerName:response.order.buyerName,totalCents:response.order.totalCents,discountCents:response.order.discountCents,confirmationToken:response.order.confirmationToken,testMode:response.order.testMode,venmoUsername:selectedVenmo,items:orderItems.map(l=>({name:l.name,quantity:l.quantity}))};
        savePendingPayment(pendingPayment);
        document.querySelector('.csw-overlay')?.remove();
        busy=false;
        completionModal();
        if(!response.order.testMode){
          const url=venmoUrl(response.order.totalCents,note);
          if(venmoWindow){venmoWindow.location.href=url;}else{window.location.assign(url);}
        }
      }catch(err){
        if(venmoWindow&&!venmoWindow.closed)venmoWindow.close();
        toast(err.message);button.textContent='Continue to Venmo ↗';busy=false;
      }
    }
  });
  root.addEventListener('input',e=>{if(e.target.matches('[data-buyer-name]')){buyerName=e.target.value;return;}if(e.target.matches('[data-discount-code]')){discountCode=e.target.value;if(discountCode.toUpperCase().replace(/[^A-Z0-9_-]/g,'')!==validatedDiscountCode)discountApplied=false;return;}if(e.target.matches('[data-product-search]')){search=e.target.value;const caret=search.length;render();const input=root.querySelector('[data-product-search]');if(input){input.focus();input.setSelectionRange(caret,caret);}}});
  root.addEventListener('change',e=>{if(e.target.matches('[data-remember-buyer]')){rememberBuyer=e.target.checked;return;}if(e.target.id==='csw-venmo-choice'){selectedVenmo=e.target.value;const link=root.querySelector('.csw-open-venmo');if(link)link.href=venmoUrl(total().total);}});
  root.addEventListener('error',e=>{if(e.target.matches('.csw-product-visual img')){const visual=e.target.parentElement;e.target.remove();visual.classList.remove('has-image');visual.classList.add('is-placeholder');}},true);
  cleanTheme();
  load();
})();
