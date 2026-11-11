const fs=require('fs'),vm=require('vm');
const code=fs.readFileSync(require('path').join(__dirname, '..', '..', 'js', 'dist', 'admin.js') + '','utf8');
const mods=new Map(), reg={_webpack_runtimes:{},add(){},get(ns,id){const m=mods.get(ns+':'+id); if(!m) console.log('NON TROVATO',id); return m;}};
const registered=[]; const inits=[];
class Component{ oninit(){} }
const app={initializers:{add:(n,f)=>inits.push(f)}, translator:{trans:k=>k}, forum:{attribute:()=>'https://x/api'}, request:()=>Promise.resolve({data:[]}), alerts:{show(){}},
  registry:{for:(id)=>({registerSetting:(s)=>{registered.push({id,s}); return this;}})}};
mods.set('core:admin/app',app); mods.set('core:common/Component',Component); mods.set('core:common/components/Button',()=>({})); mods.set('core:common/components/LoadingIndicator',()=>({}));
const m=(t,a,...c)=>({t,a,c}); m.redraw=()=>{};
const ctx=vm.createContext({flarum:{reg,extensions:{}},m,console,window:{},setTimeout,Promise,Error,Object,Array,String});
try{
  vm.runInContext(code,ctx); inits.forEach(f=>f());
  const keys=registered.filter(r=>r.s&&r.s.setting).map(r=>r.s.setting.replace('peopleinside-powcaptcha.',''));
  const panel=registered.filter(r=>typeof r.s==='function');
  console.log('admin: bundle ok. impostazioni registrate:',keys.join(', '));
  console.log('admin: pannello log registrato:',panel.length===1,'| select livello ha opzioni 1/2/3:',JSON.stringify(Object.keys(registered.find(r=>r.s.setting==='peopleinside-powcaptcha.level').s.options)));
  const el=panel[0].s(); console.log('admin: il pannello si costruisce senza errori:',!!el);
  process.exit(0);
}catch(e){console.log('ERRORE admin:',String(e.message).slice(0,200));process.exit(1);}
