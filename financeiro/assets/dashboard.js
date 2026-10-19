(() => {
    'use strict';
    const root = document.querySelector('#k8f');
    if (!root) return;
    const $ = s => root.querySelector(s), form = $('#kf-filters');
    const state = {page:1, pages:0, filters:{}, rows:[], selected:new Set(), syncing:false, stop:false, loading:false};
    const currency = v => v === null || v === undefined ? 'Não informado' : new Intl.NumberFormat('pt-BR',{style:'currency',currency:'BRL'}).format(Number(v)/100);
    const date = v => v ? String(v).slice(0,10).split('-').reverse().join('/') : 'Não informado';
    const el = (tag,text,cls) => {const n=document.createElement(tag); if(text!==undefined)n.textContent=text; if(cls)n.className=cls;return n;};
    const columns = [ ['visible','Na última varredura'],['seq','Número'],['customer','Cliente'],['document','CPF/CNPJ'],['description','Descrição'],['state','Situação'],['kind','Tipo'],['methods','Métodos oferecidos'],['method_paid','Método utilizado'],['amount','Valor nominal'],['paid','Pago informado'],['fee','Tarifa'],['net','Líquido'],['created_at','Criação'],['due_at','Vencimento'],['paid_at','Pagamento'],['synced_at','Atualizado em'],['paid_source','Origem do pago'],['fee_source','Origem da tarifa'],['pay_count','Transações Pay'],['pay_state','Status Pay'],['pay_installments','Parcelas retornadas'],['pay_fees','Taxas Pay'],['pay_settled','Liquidado Pay'],['pay_settled_at','Liquidação Pay'],['pay_available_at','Disponibilização Pay'],['pay_ids','IDs Pay'] ];
    const hidden = new Set(['visible','document','description','methods','fee','net','created_at','paid_at','synced_at','paid_source','fee_source','pay_count','pay_ids','pay_available_at','kind','method_paid']);
    const monetary = new Set(['amount','paid','fee','net','pay_fees']);
    function value(row,key) {if(['pay_installments','pay_fees','pay_settled','pay_settled_at','pay_available_at'].includes(key)&&Number(row.pay_count)>1)return 'Múltiplas · ver detalhes';if(key==='pay_installments')return row[key]===null||row[key]===undefined?'Não informado':String(row[key]);if(key==='pay_settled')return row[key]===null||row[key]===undefined?'Não informado':Number(row[key])===1?'Sim':'Não';if(key==='visible')return Number(row.visible)===1?'Sim':'Não — verificar no K8';return monetary.has(key)?currency(row[key]):key.endsWith('_at')?date(row[key]):(row[key]||'—');}
    function message(text,error=false){const box=$('#kf-message');box.hidden=!text;box.textContent=text;box.className=error?'error':'success';}
    function payload(op,extra={}){return new URLSearchParams({action:'k8f',nonce:K8F.nonce,op,...extra});}
    async function api(op,extra={}) {
        const response=await fetch(K8F.url,{method:'POST',credentials:'same-origin',body:payload(op,extra)});
        let body; try{body=await response.json();}catch(e){throw new Error('Resposta inesperada do WordPress. Verifique a sessão e o log do servidor.');}
        if(!response.ok||!body.success)throw new Error(body.data?.message||'Requisição recusada. Recarregue a página.');
        return body.data;
    }
    const filters=()=>({...state.filters,page:state.page,sort:$('#kf-sort').value,direction:$('#kf-direction').value,size:$('#kf-size').value});
    function syncStatus(s){
        const phase={charges:'Importando cobranças',customers:'Atualizando CPF/CNPJ dos clientes',done:'Varredura concluída'};
        $('#kf-sync-status').textContent=`${phase[s.phase]||'Ainda não sincronizado'} · ${s.cached||0} cobranças · ${s.customer_pending||0} clientes pendentes · ${s.missing||0} ausentes na última varredura${s.complete_at?' · Último ciclo: '+s.complete_at:''}`;
        if(s.error)message(s.error,true);
        else if(s.customer_pending&&s.customer_warning)message(s.customer_warning,true);
    }
    function metrics(m){
        const count=Number(m.count||0),cards=[
            ['Cobranças',count.toLocaleString('pt-BR'),'Registros no filtro'],
            ['Total nominal',currency(m.nominal),'Inclui todos os estados do filtro'],
            ['Pagas · nominal',currency(m.paid_nominal),`${m.paid_count||0} cobranças com estado Paga`],
            ['Em aberto · nominal',currency(m.open_nominal),'Pendentes, inadimplentes e protestadas'],
            ['Vencidas · nominal',currency(m.overdue_nominal),'Em aberto com vencimento anterior a hoje'],
            ['Canceladas · nominal',currency(m.cancelled),'Cobranças com estado Cancelada'],
            ['Pago informado',currency(m.paid),`Cob ou Pay com vínculo único · ${m.paid_known||0} de ${count} cobranças`],
            ['Tarifas informadas',currency(m.fee),`Cob ou Pay com vínculo único · ${m.fee_known||0} de ${count} cobranças`],
            ['Líquido informado',currency(m.net),`Campo presente em ${m.net_known||0} de ${count} registros`],
            ['Ticket nominal',count?currency(Math.round(Number(m.nominal)/count)):'—','Média de todos os estados do filtro']
        ];
        const container=$('#kf-metrics');container.replaceChildren();
        for(const [title,total,note] of cards){const card=el('article',undefined,'kf-card');card.append(el('span',title),el('strong',total),el('small',note));container.append(card);}
    }
    function bars(selector,rows){
        const box=$(selector);box.replaceChildren();
        if(!rows?.length){box.append(el('p','Sem dados neste filtro.','kf-note'));return;}
        const max=Math.max(1,...rows.map(r=>Math.abs(Number(r.amount))));
        for(const row of rows){const item=el('div',undefined,'kf-bar-row'),caption=el('div',undefined,'kf-bar-caption'),track=el('div',undefined,'kf-bar-track'),bar=el('div',undefined,'kf-bar');caption.append(el('span',row.label||'Não informado'),el('span',currency(row.amount)));bar.style.width=`${Math.max(0,Math.abs(Number(row.amount))/max*100)}%`;track.append(bar);item.append(caption,track);box.append(item);}
    }
    function updateOptions(options){for(const name of ['state','kind','method_paid']){const select=form.elements[name],current=select.value;select.replaceChildren(new Option('Todos',''));for(const v of options[name]||[])select.add(new Option(v,v));select.value=current;}}
    function selection(){ $('#kf-selected').textContent=`${state.selected.size} selecionadas`; }
    function renderTable(){
        const head=el('tr'),first=el('th'),all=document.createElement('input');all.type='checkbox';all.setAttribute('aria-label','Selecionar esta página');all.checked=state.rows.length>0&&state.rows.every(r=>state.selected.has(String(r.id)));all.addEventListener('change',()=>{for(const r of state.rows){if(all.checked&&state.selected.size<500)state.selected.add(String(r.id));else if(!all.checked)state.selected.delete(String(r.id));}renderTable();});first.append(all);head.append(first);
        for(const [key,title]of columns)if(!hidden.has(key))head.append(el('th',title));head.append(el('th','Detalhes'));$('#kf-head').replaceChildren(head);
        const body=$('#kf-body');body.replaceChildren();
        for(const row of state.rows){const tr=el('tr'),td=el('td'),check=document.createElement('input');check.type='checkbox';check.checked=state.selected.has(String(row.id));check.setAttribute('aria-label',`Selecionar cobrança ${row.seq||row.remote_id}`);check.addEventListener('change',()=>{if(check.checked&&state.selected.size>=500){check.checked=false;message('Limite de 500 selecionadas. Para exportar tudo, use Exportar filtros.',true);return;}if(check.checked)state.selected.add(String(row.id));else state.selected.delete(String(row.id));selection();});td.append(check);tr.append(td);for(const[key]of columns)if(!hidden.has(key)){const cell=el('td',value(row,key));if(key==='state'){cell.replaceChildren(el('span',value(row,key),'kf-badge '+(row.state==='Paga'?'paid':row.state==='Cancelada'?'cancelled':'pending')));}tr.append(cell);}const action=el('td'),button=el('button','Ver');button.addEventListener('click',()=>detail(row));action.append(button);tr.append(action);body.append(tr);}
        if(!state.rows.length){const tr=el('tr'),td=el('td','Nenhuma cobrança encontrada. Confira os filtros e a sincronização.');td.colSpan=columns.length+2;tr.append(td);body.append(tr);}
        selection();$('#kf-page').textContent=`Página ${state.pages?state.page:0} de ${state.pages}`;$('#kf-prev').disabled=state.page<=1;$('#kf-next').disabled=state.page>=state.pages;
    }
    function detail(row){
        const content=$('#kf-detail');content.replaceChildren();const list=el('dl',undefined,'kf-detail');for(const[key,title]of [['remote_id','ID K8'],...columns])list.append(el('dt',title),el('dd',value(row,key)));content.append(list);root.dispatchEvent(new CustomEvent('k8f:charge',{detail:{row,container:content}}));
        const button=el('button','Consultar nomes dos campos da API'),schema=el('pre');button.addEventListener('click',async()=>{button.disabled=true;try{const paths=await api('schema',{id:row.id});schema.textContent=paths.map(p=>`${p.path} (${p.type})`).join('\n');}catch(e){schema.textContent=e.message;}finally{button.disabled=false;}});content.append(button,el('p','A consulta abaixo mostra apenas nomes e tipos dos campos, para ajustar o mapeamento nas configurações.','kf-note'),schema);$('#kf-dialog').showModal();
    }
    let requestId=0;
    async function load(){
        const currentRequest=++requestId;state.loading=true;root.setAttribute('aria-busy','true');
        try{const data=await api('query',{filters:JSON.stringify(filters())});if(currentRequest!==requestId)return;state.rows=data.rows;state.pages=data.pages;metrics(data.metrics);bars('#kf-states',data.states);bars('#kf-months',data.months);bars('#kf-methods',data.methods);updateOptions(data.options);syncStatus(data.sync);$('#kf-count').textContent=`${data.total} registros`;renderTable();}
        catch(e){if(currentRequest===requestId)message(e.message,true);}finally{if(currentRequest===requestId){state.loading=false;root.removeAttribute('aria-busy');}}
    }
    async function sync(restart=false){
        if(state.syncing)return;state.syncing=true;state.stop=false;$('#kf-stop').hidden=false;$('#kf-sync').disabled=true;$('#kf-restart').disabled=true;message('');
        try{let first=true;while(!state.stop){const data=await api('sync',{restart:restart&&first?'1':''});first=false;syncStatus(data);if(data.phase==='done')break;await new Promise(resolve=>setTimeout(resolve,350));}await load();}
        catch(e){message(e.message,true);}finally{state.syncing=false;$('#kf-stop').hidden=true;$('#kf-sync').disabled=false;$('#kf-restart').disabled=false;}
    }
    async function exportCsv(selected=false){
        if(selected&&!state.selected.size){message('Selecione ao menos uma cobrança.',true);return;}
        const button=$(selected?'#kf-export-selected':'#kf-export');button.disabled=true;
        try{
            const f=selected?{ids:[...state.selected]}:filters();
            const r=await fetch(K8F.url,{method:'POST',credentials:'same-origin',body:payload('export',{filters:JSON.stringify(f)})});
            if(!r.ok||!(r.headers.get('Content-Type')||'').includes('text/csv')){const e=await r.json();throw new Error(e.data?.message||'Falha na exportação.');}
            const url=URL.createObjectURL(await r.blob()),a=el('a');a.href=url;a.download='k8-cobrancas.csv';document.body.append(a);a.click();a.remove();setTimeout(()=>URL.revokeObjectURL(url),1000);message('CSV exportado. Campos não informados ficam vazios.');
        }catch(e){message(e.message,true);}finally{button.disabled=false;}
    }
    form.addEventListener('submit',e=>{e.preventDefault();state.filters=Object.fromEntries(new FormData(form));state.page=1;state.selected.clear();message('');load();});
    form.addEventListener('reset',()=>{setTimeout(()=>{state.filters={};state.page=1;state.selected.clear();message('');load();},0);});
    $('#kf-prev').addEventListener('click',()=>{if(!state.loading){state.page--;load();}});$('#kf-next').addEventListener('click',()=>{if(!state.loading){state.page++;load();}});
    for(const id of ['#kf-sort','#kf-direction','#kf-size'])$(id).addEventListener('change',()=>{state.page=1;load();});
    $('#kf-sync').addEventListener('click',()=>sync());$('#kf-restart').addEventListener('click',()=>sync(true));$('#kf-stop').addEventListener('click',()=>{state.stop=true;message('Pausa após a consulta atual. A atualização automática, se habilitada, continua pelo servidor.');});
    $('#kf-test').addEventListener('click',async()=>{const b=$('#kf-test');b.disabled=true;try{message((await api('test')).message);}catch(e){message(e.message,true);}finally{b.disabled=false;}});
    $('#kf-export').addEventListener('click',()=>exportCsv());$('#kf-export-selected').addEventListener('click',()=>exportCsv(true));
    $('#kf-refresh').addEventListener('click',async()=>{if(!state.selected.size){message('Selecione cobranças para atualizar.',true);return;}const b=$('#kf-refresh');b.disabled=true;try{let count=0;for(const id of state.selected){await api('refresh',{id});count++;message(`Atualizadas ${count} de ${state.selected.size}.`);}await load();}catch(e){message(e.message,true);}finally{b.disabled=false;}});
    $('#kf-close').addEventListener('click',()=>$('#kf-dialog').close());
    for(const[key,title]of columns){const label=el('label'),input=document.createElement('input');input.type='checkbox';input.checked=!hidden.has(key);input.addEventListener('change',()=>{input.checked?hidden.delete(key):hidden.add(key);renderTable();});label.append(input,document.createTextNode(title));$('#kf-columns').append(label);}
    root.addEventListener('k8f:reload',load);
    load();
})();
