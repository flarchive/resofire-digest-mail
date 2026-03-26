(()=>{var t={n:o=>{var s=o&&o.__esModule?()=>o.default:()=>o;return t.d(s,{a:s}),s},d:(o,s)=>{for(var n in s)t.o(s,n)&&!t.o(o,n)&&Object.defineProperty(o,n,{enumerable:!0,get:s[n]})},o:(t,o)=>Object.prototype.hasOwnProperty.call(t,o)},o={};(()=>{"use strict";

const _app=flarum.reg.get("core","forum/app");var app=t.n(_app);
const _extend=flarum.reg.get("core","common/extend");
const _SettingsPage=flarum.reg.get("core","forum/components/SettingsPage");var SettingsPage=t.n(_SettingsPage);
const _Component=flarum.reg.get("core","common/Component");var Component=t.n(_Component);

function g(t,o){return g=Object.setPrototypeOf?Object.setPrototypeOf.bind():function(t,o){return t.__proto__=o,t},g(t,o)}
function _(t,o){t.prototype=Object.create(o.prototype),t.prototype.constructor=t,g(t,o)}

var DigestFrequencySetting=function(base){
  function C(){return base.apply(this,arguments)||this}
  _(C,base);

  var p=C.prototype;

  p.oninit=function(vnode){
    base.prototype.oninit.call(this,vnode);
    this.saving=false;
    this.saved=false;
    this.error=null;
  };

  p.view=function(){
    var self=this;
    var user=this.attrs.user;
    var value=user.attribute("digestFrequency")||"off";

    var allowed=app().forum.attribute("digestAllowedFrequencies")||{daily:false,weekly:true,monthly:true};
    var effectiveValue=(value!=="off"&&!allowed[value])?"off":value;

    var options=[
      m("option",{value:"off"},app().translator.trans("resofire-digest-mail.forum.settings.frequency_off"))
    ];
    if(allowed.daily)  options.push(m("option",{value:"daily"},  app().translator.trans("resofire-digest-mail.forum.settings.frequency_daily")));
    if(allowed.weekly) options.push(m("option",{value:"weekly"}, app().translator.trans("resofire-digest-mail.forum.settings.frequency_weekly")));
    if(allowed.monthly)options.push(m("option",{value:"monthly"},app().translator.trans("resofire-digest-mail.forum.settings.frequency_monthly")));

    return m("div",{className:"Form-group"},
      m("label",{className:"label",for:"resofire-digest-mail-frequency"},
        app().translator.trans("resofire-digest-mail.forum.settings.digest_label")
      ),
      m("div",{className:"helpText"},
        app().translator.trans("resofire-digest-mail.forum.settings.digest_help")
      ),
      m("div",{style:"display:flex;align-items:center;gap:10px;margin-top:8px;"},
        m("select",{
          id:"resofire-digest-mail-frequency",
          className:"FormControl",
          style:"padding-top:6px;padding-bottom:8px;height:auto;line-height:1.5;",
          disabled:this.saving,
          value:effectiveValue,
          onchange:function(e){self.save(user,e.target.value);}
        },options),
        this.saving?m("span",{className:"LoadingIndicator","aria-hidden":"true"}):null,
        (this.saved&&!this.saving)
          ?m("span",{style:"color:var(--control-success-color,#3d8b3d);font-size:13px;"},
              "\u2713 "+app().translator.trans("resofire-digest-mail.forum.settings.saved"))
          :null
      ),
      this.error
        ?m("div",{className:"Alert Alert--error",style:"margin-top:8px;padding:8px 12px;font-size:13px;"},this.error)
        :null
    );
  };

  p.save=function(user,value){
    var self=this;
    var frequency=value==="off"?null:value;
    this.saving=true;this.saved=false;this.error=null;m.redraw();
    user.save({digestFrequency:frequency})
      .then(function(){
        self.saving=false;self.saved=true;m.redraw();
        setTimeout(function(){self.saved=false;m.redraw();},3000);
      })
      .catch(function(e){
        self.saving=false;
        self.error=(e&&e.message)||app().translator.trans("resofire-digest-mail.forum.settings.save_error");
        m.redraw();
      });
  };

  return C;
}(Component());

app().initializers.add("resofire-digest-mail",function(){
  (0,_extend.extend)(SettingsPage().prototype,"notificationsItems",function(items){
    var user=this.user;
    if(!user||!app().session.user||user.id()!==app().session.user.id())return;
    items.add("digestFrequency",m(DigestFrequencySetting,{user:user}),50);
  });
});

})(),module.exports=o})();
