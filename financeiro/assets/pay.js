(() => {
    'use strict';
    const root=document.querySelector('#k8f');if(!root)return;
    const $=s=>root.querySelector(s);
    async function load(){root.dispatchEvent(new CustomEvent('k8f:reload'));}
    const el=(tag,text,cls)=>{const n=document.createElement(tag);if(text!==undefined)n.textContent=text;if(cls)n.className=cls;return n;};
    const money=v=>v===null||v===undefined?'Não informado':new Intl.NumberFormat('pt-BR',{style:'currency',currency:'BRL'}).format(Number(v)/100);
    const date=v=>v?String(v).slice(0,10).split('-').reverse().join('/'):'Não informado';
    const yes=v=>v===null||v===undefined?'Não informado':Number(v)===1?'Sim':'Não';
    const state={page:1,pages:0,filters:{kind:'Cartao'},busy:false,stop:false,request:0};
    function msg(text,error=false){$('#kp-message').textContent=text;$('#kp-message').className=error?'error':'success';}
    function payload(op,data={}){return new URLSearchParams({action:'k8f_pay',nonce:K8F.nonce,op,...data});}
    async function api(op,data={}){const r=await fetch(K8F.url,{method:'POST',credentials:'same-origin',body:payload(op,data)});let b;try{b=await r.json();}catch(e){throw Error('Resposta Pay inesperada. Recarregue a sessão e confira o servidor.');}if(!r.ok||!b.success)throw Error(b.data?.message||'Consulta Pay recusada.');return b.data;}
    function syncInfo(s){$('#kp-sync-status').textContent=s.from?`Período de criação consultado: ${date(s.from)} a ${date(s.to)} · ${s.done?'Consultas do período concluídas':'Próximo dia: '+date(s.day)} · ${s.processed||0} registros processados (inclui reconsultas)${s.error?' · '+s.error:''}${s.cron_error?' · Atualização automática: '+s.cron_error:''}`:'Nenhum período Pay importado ainda.';}
    function partsTable(r){
        const wrap=el('div',undefined,'kf-table-wrap'),table=el('table'),head=el('thead'),tr=el('tr');
        for(const t of ['Número informado','Estado da parcela','Valor','Data de pagamento','Motivo de cancelamento'])tr.append(el('th',t));head.append(tr);table.append(head);
        const body=el('tbody');
        for(const p of r.installments||[]){const row=el('tr');for(const v of [p.number||'Não informado',p.state||'Não informado',money(p.amount),date(p.paid_at),p.reason||'—'])row.append(el('td',v));body.append(row);}
        if(!r.installments?.length){const row=el('tr'),td=el('td','O gateway não retornou uma lista de parcelas nesta consulta.');td.colSpan=5;row.append(td);body.append(row);}
        table.append(body);wrap.append(table);return wrap;
    }
    function transaction(r,onRefresh){
        const box=el('section',undefined,'kp-transaction');box.append(el('h3',`${r.kind} · ${r.remote_id}`));
        const list=el('dl',undefined,'kf-detail');
        const pairs=[['Cliente',r.customer||'Não informado'],['Pedido',r.order_number||'Não informado'],['Estado Pay',r.state],['Valor informado',money(r.amount)],['Pago informado',money(r.paid)],['Taxas informadas',money(r.fees)],['Pago menos taxas (cálculo)',money(r.net_estimate)],['Liquidado informado',yes(r.settled)],['Data de pagamento',date(r.paid_at)],['Data de liquidação',date(r.settled_at)],['Data de disponibilização',date(r.available_at)],['Parcelas retornadas',r.installment_count===null?'Não informado':String(r.installment_count)],['Soma das parcelas retornadas',money(r.installment_sum)],['Última consulta',r.synced_at||'—']];
        if(r.charge_remote_id)pairs.push(['Cobrança vinculada',r.charge_remote_id],['Estado na cobrança',r.charge_state||'Não informado']);
        if(r.link_source)pairs.push(['Origem do vínculo',r.link_source==='manual'?'Associação manual':r.link_source==='id'?'ID exato da API':'Associação automática bloqueada']);
        for(const[k,v]of pairs)list.append(el('dt',k),el('dd',v));box.append(list,partsTable(r));
        box.append(el('p','O cálculo pago menos taxas não considera automaticamente splits, estornos, antecipações ou outras retenções. Datas de pagamento das parcelas não são previsões de repasse.','kf-note'));
        const refresh=el('button','Consultar novamente no Pay');refresh.addEventListener('click',async()=>{refresh.disabled=true;try{await api('one',{remote:r.remote_id});await onRefresh();await load();}catch(e){box.append(el('p',e.message,'error'));}finally{refresh.disabled=false;}});box.append(refresh);return box;
    }
    async function sync(restart){
        if(state.busy)return;state.busy=true;state.stop=false;$('#kp-start').disabled=true;$('#kp-resume').disabled=true;$('#kp-stop').hidden=false;msg('Consultando o gateway…');
        try{let first=true;while(!state.stop){const extra=restart&&first?{from:$('#kp-import-from').value,to:$('#kp-import-to').value}:{};const s=await api('sync',extra);first=false;syncInfo(s);if(s.done){msg('Consultas do período concluídas. Confira a cobertura com o portal K8.');break;}await new Promise(r=>setTimeout(r,350));}await load();}catch(e){msg(e.message,true);}finally{state.busy=false;$('#kp-start').disabled=false;$('#kp-resume').disabled=false;$('#kp-stop').hidden=true;}
    }
    $('#kp-start').addEventListener('click',()=>sync(true));$('#kp-resume').addEventListener('click',()=>sync(false));$('#kp-stop').addEventListener('click',()=>{state.stop=true;msg('Pausa após a consulta atual. O cron, se habilitado, continua no servidor.');});
    $('#kp-test').addEventListener('click',async()=>{const b=$('#kp-test');b.disabled=true;try{msg((await api('test')).message);}catch(e){msg(e.message,true);}finally{b.disabled=false;}});
    root.addEventListener('k8f:charge',async e=>{
        const {row,container}=e.detail,area=el('section',undefined,'kp-linked');area.append(el('h3','Gateway K8 Pay e parcelamento'));container.append(area);
        const info=el('div'),message=el('p',undefined,'kf-note');area.append(info,message);
        const link=el('div',undefined,'kf-actions'),input=el('input');input.placeholder='ID da transação Pay já importada';input.setAttribute('aria-label','ID Pay para associar');const button=el('button','Associar à cobrança'),fetchButton=el('button','Consultar ID no Pay');fetchButton.addEventListener('click',async()=>{fetchButton.disabled=true;try{await api('one',{remote:input.value.trim()});message.textContent='Transação consultada. Confira o ID e clique em Associar à cobrança.';await render();await load();}catch(err){message.textContent=err.message;}finally{fetchButton.disabled=false;}});link.append(input,fetchButton,button);area.append(link,el('p','Associe apenas após conferir o ID no portal K8. O vínculo organiza o dashboard e não altera dados no gateway.','kf-note'));
        async function render(){
            try{const rows=await api('charge',{id:row.id});info.replaceChildren();if(!rows.length)info.append(el('p','Nenhuma transação Pay vinculada. Confira o campo do ID nas configurações ou associe um ID importado.','kf-note'));
                for(const r of rows){const item=transaction(r,render),unlink=el('button','Remover vínculo');unlink.addEventListener('click',async()=>{unlink.disabled=true;try{await api('link',{id:row.id,remote:r.remote_id,unlink:'1'});await render();await load();}catch(err){message.textContent=err.message;}finally{unlink.disabled=false;}});item.append(unlink);info.append(item);}
            }catch(err){message.textContent=err.message;}
        }
        button.addEventListener('click',async()=>{button.disabled=true;message.textContent='';try{await api('link',{id:row.id,remote:input.value.trim()});input.value='';await render();await load();}catch(err){message.textContent=err.message;}finally{button.disabled=false;}});await render();
    });
})();
