(() => {
'use strict';
const esc = v => String(v ?? '').replace(/[&<>"']/g, c => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c]));
const money = v => new Intl.NumberFormat('pt-BR',{style:'currency',currency:'BRL'}).format(Number(v)/100);
const help = `<div class="jao-help"><a class="jao-link secondary" href="https://wa.me/5541996261884?text=Ol%C3%A1%2C%20preciso%20atualizar%20meu%20WhatsApp%20no%20cadastro%20para%20acessar%20as%20atividades%20opcionais." target="_blank" rel="noopener noreferrer">Atualizar WhatsApp com a secretaria</a><p>Você também pode alterar seu número pelo Portal do Aluno. Após alterar o cadastro, aguarde a sincronização da escola e consulte seu CPF novamente.</p></div>`;
document.querySelectorAll('.jao').forEach(root => {
 const view=root.querySelector('.jao-view'), notice=root.querySelector('.jao-notice');
 let token='',student='',csrf='',filters={},page=1;
 const say=(text,error=false)=>{notice.textContent=text;notice.classList.toggle('error',error);};
 async function req(op,data={},raw=false){
   const r=await fetch(JAO.url,{method:'POST',credentials:'same-origin',cache:'no-store',body:new URLSearchParams({action:'jao',op,token,csrf,...data})});
   if(raw&&r.ok&&r.headers.get('content-type')?.includes('text/csv'))return r.blob();
   let j;try{j=await r.json();}catch{throw new Error('Resposta inesperada. Confira a conexão e tente novamente.');}
   if(!j.success)throw new Error(j.data?.message||'Não foi possível concluir.');return j.data;
 }
 async function run(fn){
   root.querySelectorAll('button').forEach(b=>b.disabled=true);root.setAttribute('aria-busy','true');say('');
   try{await fn();}catch(e){say(e.message,true);}finally{root.removeAttribute('aria-busy');root.querySelectorAll('button').forEach(b=>b.disabled=false);}
 }
 function bind(selector,fn,event='click'){view.querySelector(selector)?.addEventListener(event,e=>{e.preventDefault();run(()=>fn(e));});}
 function reset(){token='';student='';say('');cpfView();}
 function cpfView(){
   view.innerHTML=`<div class="jao-steps">1. Identificação → 2. Validação → 3. Atividades</div><form class="jao-login"><label>CPF do responsável<input name="cpf" inputmode="numeric" autocomplete="off" maxlength="14" placeholder="000.000.000-00" required></label><button>Consultar CPF</button></form>${help}`;
   bind('form',async()=>{
     const cpf=view.querySelector('[name=cpf]').value.replace(/\D/g,''); const d=await req('preview',{cpf});token=d.token;
     view.innerHTML=`<div class="jao-login jao-card"><h3>Confirme seu WhatsApp</h3><p>Um código de validação será enviado ao WhatsApp cadastrado com final <strong>${esc(d.last4)}</strong>.</p><p>Se você tem acesso a esse número, toque em continuar.</p><div class="jao-actions"><button data-send>Continuar</button><button class="secondary" data-back>Voltar</button></div></div>${help}`;
     bind('[data-send]',async()=>{await req('send');otpView();say('Código solicitado. Confira seu WhatsApp.');});bind('[data-back]',reset);
   },'submit');
 }
 function otpView(){
   view.innerHTML=`<form class="jao-login"><h3>Digite o código recebido</h3><label>Código de 6 dígitos<input name="code" inputmode="numeric" autocomplete="one-time-code" pattern="[0-9]{6}" maxlength="6" required></label><p>O código é válido por 5 minutos.</p><div class="jao-actions"><button>Validar e ver alunos</button><button type="button" class="secondary" data-reset>Solicitar outro código</button></div></form>${help}`;
   bind('form',async()=>{const d=await req('check',{code:view.querySelector('[name=code]').value});token=d.token;await studentsView();},'submit');bind('[data-reset]',reset);
 }
 async function studentsView(){
   const d=await req('students');
   view.innerHTML=`<h3>Para qual aluno deseja se inscrever?</h3><div class="jao-grid">${d.students.map(s=>`<button class="jao-card secondary" data-student="${esc(s.key)}"><strong>${esc(s.name)}</strong><small>Turma ${esc(s.class)}</small></button>`).join('')}</div><div class="jao-actions"><button class="secondary" data-reset>Sair</button></div>`;
   if(!d.students.length)view.insertAdjacentHTML('afterbegin','<p>Nenhum aluno disponível neste cadastro.</p>');
   view.querySelectorAll('[data-student]').forEach(b=>b.addEventListener('click',()=>run(async()=>{student=b.dataset.student;await catalogView(d.students.find(s=>s.key===student));})));bind('[data-reset]',reset);
 }
 async function catalogView(s){
   const d=await req('catalog',{student});
   view.innerHTML=`<div class="jao-steps">3. Escolha as atividades</div><h3>${esc(s.name)} · ${esc(s.class)}</h3><p>Marque quantas atividades desejar. Os valores são por inscrição; não geram mensalidades automáticas.</p><div class="jao-grid">${d.items.map(a=>`<label class="jao-card"><input type="checkbox" value="${a.id}" ${a.reserved?'disabled':''}><span><strong>${esc(a.name)}</strong><small>${esc(a.description)}</small><b>${money(a.price)}</b>${a.reserved?`<small>Inscrição existente · ${esc(a.state)}</small>`:''}</span></label>`).join('')}</div>${!d.items.length?'<p class="jao-empty">Nenhuma atividade disponível para esta turma no momento.</p>':''}<div class="jao-total"><span>Total selecionado</span><br><strong data-total>${money(0)}</strong><label>Forma de pagamento<select data-method><option value="Pix">PIX</option><option value="Boleto">Boleto</option><option value="Cartao">Cartão de crédito</option></select></label><div class="jao-actions"><button data-pay>Ir para pagamento</button><button class="secondary" data-students>Escolher outro aluno</button></div></div>${d.orders.length?'<h3>Pedidos deste aluno</h3>'+d.orders.map(o=>`<div class="jao-order">Pedido #${o.id} · ${esc(o.state)} ${o.url?`<a href="${esc(o.url)}" class="jao-link secondary">Continuar pagamento</a>`:''}</div>`).join(''):''}`;
   const selected=()=>d.items.filter(a=>!a.reserved&&view.querySelector(`input[value="${a.id}"]`)?.checked);
   view.querySelectorAll('input[type=checkbox]').forEach(i=>i.addEventListener('change',()=>{view.querySelector('[data-total]').textContent=money(selected().reduce((sum,a)=>sum+a.price,0));}));
   bind('[data-students]',studentsView);
   bind('[data-pay]',async()=>{
     const items=selected();if(!items.length)throw new Error('Selecione pelo menos uma atividade.');
     const data=await req('checkout',{student,ids:JSON.stringify(items.map(a=>a.id)),expected_total:items.reduce((sum,a)=>sum+a.price,0),method:view.querySelector('[data-method]').value});
     const url=new URL(data.url);if(url.protocol!=='https:')throw new Error('URL de pagamento inválida. Procure a secretaria.');
     say('Pedido registrado. Abrindo o pagamento K8…');window.location.assign(url.href);
   });
 }
 function dashLogin(){
   view.innerHTML='<form class="jao-login"><label>Senha do painel<input name="password" type="password" autocomplete="current-password" required></label><button>Entrar no acompanhamento</button></form>';
   bind('form',async()=>{const d=await req('dash_login',{password:view.querySelector('[name=password]').value});csrf=d.csrf;await dashView();},'submit');
 }
 async function dashView(){
   const d=await req('dash_query',{...filters,page});
   const opts=(rows,key,label,current)=>rows.map(r=>`<option value="${esc(r[key])}" ${String(r[key])===String(current)?'selected':''}>${esc(r[label])}</option>`).join('');
   view.innerHTML=`<div class="jao-metrics"><div class="jao-card">Pedidos<strong>${Number(d.summary.orders)}</strong></div><div class="jao-card">Pago (valor nominal)<strong>${money(d.summary.paid)}</strong></div><div class="jao-card">A receber<strong>${money(d.summary.pending)}</strong></div><div class="jao-card">Para conferir<strong>${Number(d.summary.review||0)}</strong></div></div><form data-filters><div class="jao-filters"><label>Aluno / RA / responsável<input name="search" value="${esc(filters.search||'')}"></label><label>Turma<select name="class"><option value="">Todas</option>${d.classes.map(c=>`<option ${c===filters.class?'selected':''}>${esc(c)}</option>`).join('')}</select></label><label>Atividade<select name="activity"><option value="">Todas</option>${opts(d.activities,'id','name',filters.activity)}</select></label><label>Situação<select name="state"><option value="">Todas</option>${['Pendente','Paga','Inadimplente','Cancelada','Conferir','Sem cobrança'].map(s=>`<option ${s===filters.state?'selected':''}>${esc(s)}</option>`).join('')}</select></label><label>Desde<input name="from" type="date" value="${esc(filters.from||'')}"></label><label>Até<input name="to" type="date" value="${esc(filters.to||'')}"></label></div><div class="jao-actions"><button>Aplicar filtros</button><button type="button" class="secondary" data-export>Exportar CSV</button><button type="button" class="secondary" data-reload>Recarregar painel</button><button type="button" class="secondary" data-logout>Sair</button></div></form><p class="jao-help">Pago e a receber mostram os valores nominais das cobranças filtradas. Ao filtrar uma atividade, o total inclui o pedido completo. Taxas, recebimentos e parcelas estão no financeiro K8 do WordPress. A criação da cobrança não confirma pagamento.</p><div class="jao-scroll"><table><thead><tr><th>Pedido / data</th><th>Aluno / turma</th><th>Responsável</th><th>Atividades</th><th>Total</th><th>Situação</th><th>Consulta K8</th></tr></thead><tbody>${d.rows.map(r=>`<tr><td>#${r.id}<small>${esc(r.created_at)}</small></td><td>${esc(r.student)}<small>RA ${esc(r.ra)} · ${esc(r.class)}</small></td><td>${esc(r.guardian)}</td><td>${r.items.map(i=>`${esc(i.name)} · ${money(i.price)}`).join('<br>')}</td><td>${money(r.total)}<small>${esc(r.method)}</small></td><td><span class="jao-badge">${esc(r.state)}</span>${r.error?`<small>${esc(r.error)}</small>`:''}</td><td>${r.remote_id?`<button class="secondary" data-refresh="${r.id}">Consultar K8</button>`:'Conferir no WordPress'}<small>${esc(r.synced_at||'Ainda não consultado')}</small></td></tr>`).join('')}</tbody></table></div>${!d.rows.length?'<p class="jao-empty">Nenhuma inscrição encontrada.</p>':''}<div class="jao-actions"><button class="secondary" data-prev>Anterior</button><span>Página ${d.page} de ${d.pages}</span><button class="secondary" data-next>Próxima</button></div>`;
   bind('[data-filters]',async()=>{filters=Object.fromEntries(new FormData(view.querySelector('[data-filters]')));page=1;await dashView();},'submit');
   bind('[data-logout]',async()=>{await req('dash_logout');csrf='';dashLogin();});bind('[data-reload]',dashView);
   bind('[data-prev]',async()=>{page=Math.max(1,page-1);await dashView();});bind('[data-next]',async()=>{page=Math.min(d.pages,page+1);await dashView();});
   view.querySelectorAll('[data-refresh]').forEach(b=>b.addEventListener('click',()=>run(async()=>{await req('dash_refresh',{id:b.dataset.refresh});await dashView();say('Situação consultada no K8.');})));
   bind('[data-export]',async()=>{const blob=await req('dash_export',filters,true);const url=URL.createObjectURL(blob);const a=document.createElement('a');a.href=url;a.download='inscricoes-atividades.csv';a.click();setTimeout(()=>URL.revokeObjectURL(url),2000);});
 }
 if(root.dataset.mode==='dashboard'){
   req('dash_session').then(async d=>{csrf=d.csrf;try{await dashView();}catch(e){dashLogin();say(e.message,true);}}).catch(()=>dashLogin());
 }else cpfView();
});
})();
