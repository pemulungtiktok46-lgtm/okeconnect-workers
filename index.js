const json = (data, status = 200) => new Response(JSON.stringify(data), { status, headers: { 'content-type': 'application/json; charset=utf-8', 'cache-control': 'no-store' } });
const esc = (s='') => String(s).replace(/[&<>"']/g, c => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c]));
const money = n => 'Rp' + Number(n || 0).toLocaleString('id-ID');
const isAdmin = (id, env) => (env.TELEGRAM_ADMIN_IDS || '').split(',').map(x=>x.trim()).includes(String(id));
const isOperator = (id, env) => isAdmin(id,env) || (env.TELEGRAM_OPERATOR_IDS || '').split(',').map(x=>x.trim()).includes(String(id));
async function tg(env, method, payload={}) {
  if (!env.TELEGRAM_BOT_TOKEN) throw new Error('Token Telegram belum dipasang di Secrets.');
  const r = await fetch(`https://api.telegram.org/bot${env.TELEGRAM_BOT_TOKEN}/${method}`, { method:'POST', headers:{'content-type':'application/json'}, body:JSON.stringify(payload) });
  const d = await r.json(); if (!r.ok || !d.ok) throw new Error(d.description || `Telegram HTTP ${r.status}`); return d.result;
}
async function send(env, chat_id, text, reply_markup) { const p={chat_id:String(chat_id),text,parse_mode:'HTML',disable_web_page_preview:true}; if(reply_markup)p.reply_markup=reply_markup; return tg(env,'sendMessage',p); }
async function log(db, ref, type, payload={}) { await db.prepare('INSERT INTO transaction_events(reference_id,event_type,payload) VALUES(?,?,?)').bind(ref,type,JSON.stringify(payload)).run(); }
async function walletCredit(db, memberId, amount, ref, description) {
  const w=await db.prepare('SELECT balance FROM wallets WHERE member_id=?').bind(memberId).first(); if(!w) throw new Error('Wallet tidak ditemukan');
  const before=Number(w.balance), after=before+Number(amount);
  await db.batch([
    db.prepare('UPDATE wallets SET balance=?,updated_at=CURRENT_TIMESTAMP WHERE member_id=?').bind(after,memberId),
    db.prepare("INSERT INTO wallet_ledger(member_id,reference_id,type,amount,balance_before,balance_after,description) VALUES(?,?,'credit',?,?,?,?)").bind(memberId,ref,amount,before,after,description)
  ]);
}
async function register(db, from) {
  const id=String(from.id); let m=await db.prepare('SELECT * FROM members WHERE telegram_id=?').bind(id).first(); if(m)return m;
  const count=await db.prepare('SELECT COUNT(*) AS n FROM members').first(); const code='MB'+String(Number(count.n)+1).padStart(6,'0');
  try { await db.batch([
    db.prepare("INSERT INTO members(member_code,telegram_id,username,full_name,role,status) VALUES(?,?,?,?,'member','active')").bind(code,id,from.username||null,[from.first_name,from.last_name].filter(Boolean).join(' ')||'Telegram Member'),
    db.prepare('INSERT INTO wallets(member_id,balance) SELECT id,0 FROM members WHERE telegram_id=?').bind(id)
  ]); } catch(e) { /* duplicate update can arrive concurrently */ }
  return await db.prepare('SELECT * FROM members WHERE telegram_id=?').bind(id).first();
}
async function finalizeDeposit(db, env, id, adminId, approve) {
  const d=await db.prepare("SELECT * FROM deposits WHERE id=? AND status='PENDING'").bind(id).first(); if(!d)throw new Error('Deposit sudah diproses atau tidak ditemukan.');
  const newStatus=approve?'APPROVED':'REJECTED';
  if(approve){
    const w=await db.prepare('SELECT balance FROM wallets WHERE member_id=?').bind(d.member_id).first(); if(!w)throw new Error('Wallet tidak ditemukan');
    const before=Number(w.balance), after=before+Number(d.amount);
    await db.batch([
      db.prepare("UPDATE deposits SET status=?,approved_by=?,approved_at=CURRENT_TIMESTAMP WHERE id=? AND status='PENDING'").bind(newStatus,String(adminId),id),
      db.prepare('UPDATE wallets SET balance=?,updated_at=CURRENT_TIMESTAMP WHERE member_id=?').bind(after,d.member_id),
      db.prepare("INSERT INTO wallet_ledger(member_id,reference_id,type,amount,balance_before,balance_after,description) VALUES(?,?,'credit',?,?,?,'Deposit disetujui admin')").bind(d.member_id,d.deposit_code,d.amount,before,after)
    ]);
  } else {
    const res=await db.prepare("UPDATE deposits SET status=?,approved_by=?,approved_at=CURRENT_TIMESTAMP WHERE id=? AND status='PENDING'").bind(newStatus,String(adminId),id).run();
    if(!res.meta.changes)throw new Error('Deposit sudah diproses.');
  }
  const m=await db.prepare('SELECT telegram_id FROM members WHERE id=?').bind(d.member_id).first();
  if(m) await send(env,m.telegram_id,`Deposit <code>${esc(d.deposit_code)}</code>: <b>${newStatus}</b>.`);
  return newStatus;
}
async function handleTelegram(update, env) {
 const db=env.DB; const uid=update.update_id;
 const seen=await db.prepare('SELECT update_id FROM telegram_updates WHERE update_id=?').bind(uid).first(); if(seen)return;
 await db.prepare('INSERT OR IGNORE INTO telegram_updates(update_id) VALUES(?)').bind(uid).run();
 if(update.callback_query){ const cb=update.callback_query, from=String(cb.from.id); try{
   if(!isAdmin(from,env))throw new Error('Hanya admin yang dapat menyetujui deposit.');
   const m=/^(deposit_approve|deposit_reject):(\d+)$/.exec(cb.data||''); if(!m)throw new Error('Aksi tidak dikenal.');
   const result=await finalizeDeposit(db,env,Number(m[2]),from,m[1]==='deposit_approve');
   await tg(env,'answerCallbackQuery',{callback_query_id:cb.id,text:result});
  }catch(e){await tg(env,'answerCallbackQuery',{callback_query_id:cb.id,text:e.message,show_alert:true});} return;
 }
 const msg=update.message; if(!msg?.text)return; const from=String(msg.from.id), chat=String(msg.chat.id), text=msg.text.trim();
 try {
  if(text.startsWith('/start')){ const m=await register(db,msg.from); await send(env,chat,`<b>Selamat datang di OkeConnect Internal</b>\nID Member: <code>${esc(m.member_code)}</code>\nPendaftaran otomatis berhasil. Transaksi hanya diproses operator.`); }
  else if(text==='/saldo'){ const m=await db.prepare("SELECT m.member_code,w.balance FROM members m JOIN wallets w ON w.member_id=m.id WHERE m.telegram_id=? AND m.status='active'").bind(from).first(); if(!m)throw new Error('Akun belum terdaftar. Ketik /start.'); await send(env,chat,`Member: <code>${esc(m.member_code)}</code>\nSaldo: <b>${money(m.balance)}</b>`); }
  else if(text==='/admin'){ if(!isOperator(from,env))throw new Error('Akses operator ditolak.'); const rows=await db.prepare('SELECT status,COUNT(*) AS c FROM transactions GROUP BY status').all(); let out='<b>PANEL OPERASIONAL</b>\n'; for(const x of rows.results)out+=`${esc(x.status)}: ${x.c}\n`; await send(env,chat,out+'\nPerintah: /order KODEPRODUK NOMOR MEMBERID [NOMINAL]'); }
  else if(text.startsWith('/order')){ if(!isOperator(from,env))throw new Error('Hanya operator yang dapat membuat transaksi.'); throw new Error('Modul transaksi supplier belum diaktifkan. Perlu spesifikasi REST API OkeConnect resmi untuk migrasi aman; tidak akan mengirim transaksi dengan format tebakan.'); }
  else if(text.startsWith('/status ')){ if(!isOperator(from,env))throw new Error('Akses operator ditolak.'); const ref=text.slice(8).trim(); const t=await db.prepare('SELECT * FROM transactions WHERE reference_id=?').bind(ref).first(); if(!t)throw new Error('Transaksi tidak ditemukan.'); await send(env,chat,`Ref: <code>${esc(ref)}</code>\nStatus: <b>${esc(t.status)}</b>\nProduk: ${esc(t.product_code)}\nTujuan: ${esc(t.destination)}\nHarga: ${money(t.selling_price)}`); }
  else if(text.startsWith('/recheck ')){ if(!isOperator(from,env))throw new Error('Akses operator ditolak.'); throw new Error('Pemeriksaan status supplier menunggu adapter REST API OkeConnect resmi. Jangan mengirim ulang transaksi yang statusnya belum pasti.'); }
  else await send(env,chat,'Perintah tersedia: /start, /saldo, /admin.');
 }catch(e){await send(env,chat,'Gagal: '+esc(e.message));}
}
async function api(request, env, url) {
 if(!env.ADMIN_API_TOKEN)return json({error:'ADMIN_API_TOKEN belum diatur di Cloudflare Secrets.'},503);
 if(request.headers.get('x-admin-token')!==env.ADMIN_API_TOKEN)return json({error:'Token dashboard salah. Gunakan token admin dashboard, bukan token BotFather.'},401);
 if(url.pathname==='/api/summary'){
  const [m,t,p,d]=await Promise.all([
   env.DB.prepare('SELECT COUNT(*) AS n FROM members').first(),env.DB.prepare('SELECT COUNT(*) AS n FROM transactions').first(),
   env.DB.prepare("SELECT COUNT(*) AS n FROM transactions WHERE status IN ('PROCESS','SUBMITTED_UNKNOWN','SUBMITTED')").first(),
   env.DB.prepare("SELECT COUNT(*) AS n FROM deposits WHERE status='PENDING'").first()
  ]); return json({members:m.n,transactions:t.n,pending:p.n,deposits_pending:d.n});
 }
 if(url.pathname==='/api/transactions'){const r=await env.DB.prepare('SELECT reference_id,product_code,destination,selling_price,status,created_at FROM transactions ORDER BY id DESC LIMIT 100').all();return json(r.results);}
 if(url.pathname==='/api/deposits'){const r=await env.DB.prepare("SELECT d.deposit_code,d.amount,d.payment_method,d.status,d.created_at,m.member_code,m.telegram_id FROM deposits d JOIN members m ON m.id=d.member_id WHERE d.status='PENDING' ORDER BY d.id DESC LIMIT 100").all();return json(r.results);}
 return json({error:'Endpoint tidak ditemukan.'},404);
}
export default {
 async fetch(request, env, ctx){
  const url=new URL(request.url);
  try{
   if(url.pathname==='/health')return json({ok:true,service:'okeconnect-workers',time:new Date().toISOString()});
   if(url.pathname==='/telegram/webhook'&&request.method==='POST'){
    const secret=request.headers.get('x-telegram-bot-api-secret-token'); if(!env.TELEGRAM_WEBHOOK_SECRET||secret!==env.TELEGRAM_WEBHOOK_SECRET)return json({error:'Webhook secret tidak cocok.'},401);
    const update=await request.json(); ctx.waitUntil(handleTelegram(update,env)); return json({ok:true});
   }
   if(url.pathname==='/telegram/set-webhook'&&request.method==='POST'){
    const auth=request.headers.get('x-admin-token'); if(!env.ADMIN_API_TOKEN||auth!==env.ADMIN_API_TOKEN)return json({error:'Unauthorized'},401);
    const webhookSecret=env.TELEGRAM_WEBHOOK_SECRET; if(!webhookSecret)return json({error:'TELEGRAM_WEBHOOK_SECRET belum diatur.'},503);
    const result=await tg(env,'setWebhook',{url:new URL('/telegram/webhook',url.origin).toString(),secret_token:webhookSecret,allowed_updates:['message','callback_query']}); return json({ok:true,result});
   }
   if(url.pathname.startsWith('/api/'))return await api(request,env,url);
   if(url.pathname==='/okeconnect/callback')return json({error:'Callback supplier belum diaktifkan. Diperlukan format callback resmi OkeConnect untuk verifikasi tanda tangan dan pemetaan status.'},501);
   if(env.ASSETS)return env.ASSETS.fetch(request);
   return new Response('OkeConnect Worker berjalan.');
  }catch(e){return json({error:e.message||'Kesalahan server.'},500);}
 }
};
