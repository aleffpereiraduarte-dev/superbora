const OnemundoPush={
    userType:null,userId:null,reg:null,sub:null,
    init:async function(t,i){
        this.userType=t;this.userId=i;
        if(!("serviceWorker"in navigator)||!("PushManager"in window))return false;
        try{
            this.reg=await navigator.serviceWorker.register("/mercado/sw.js");
            this.sub=await this.reg.pushManager.getSubscription();
            if(this.sub)this.sync();
            return true;
        }catch(e){return false}
    },
    subscribe:async function(){
        if(!this.reg)return false;
        try{
            const p=await Notification.requestPermission();
            if(p!=="granted")return false;
            this.sub=await this.reg.pushManager.subscribe({userVisibleOnly:true,applicationServerKey:this.urlB64("BEl62iUYgUivxIkv69yViEuiBIa-Ib9-SkvMeAtA3LFgDzkrxZJjSgSnfckjBJuBkr3qBUYIHBQFLXYp5Nksh8U")});
            await this.sync();
            return true;
        }catch(e){return false}
    },
    sync:async function(){
        if(!this.sub)return;
        await fetch("/mercado/api/push_subscribe.php",{method:"POST",headers:{"Content-Type":"application/json"},body:JSON.stringify({user_type:this.userType,user_id:this.userId,subscription:this.sub.toJSON()})})
    },
    urlB64:function(b){
        const p="=".repeat((4-b.length%4)%4);
        const s=(b+p).replace(/-/g,"+").replace(/_/g,"/");
        const r=atob(s);
        const o=new Uint8Array(r.length);
        for(let i=0;i<r.length;i++)o[i]=r.charCodeAt(i);
        return o
    }
};