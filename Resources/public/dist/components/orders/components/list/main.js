define(function(){"use strict";var a=function(){this.sandbox.on("husky.datagrid.item.click",function(a){this.sandbox.emit("salesorder.orders.sidebar.getData",{data:a,callback:function(a,b){this.sandbox.emit("sulu.sidebar.set-widget","/admin/widget-groups/order-info?contact="+a+"&account="+b)}.bind(this)})},this),this.sandbox.on("sulu.list-toolbar.delete",function(){this.sandbox.emit("husky.datagrid.items.get-selected",function(a){this.sandbox.emit("sulu.salesorder.order.delete",a)}.bind(this))},this),this.sandbox.on("sulu.list-toolbar.add",function(){this.sandbox.emit("sulu.salesorder.order.new")},this)},b=function(){return[{id:"add",icon:"plus-circle","class":"highlight-white",position:1,title:this.sandbox.translate("sulu.list-toolbar.add"),callback:function(){this.sandbox.emit("sulu.list-toolbar.add")}.bind(this)},{id:"settings",icon:"gear",items:[{type:"columnOptions"}]}]};return{view:!0,layout:{content:{width:"max",leftSpace:!1,rightSpace:!1},sidebar:{width:"fixed",cssClasses:"sidebar-padding-50"}},header:{title:"salesorder.orders.title",noBack:!0,breadcrumb:[{title:"navigation.sales"},{title:"salesorder.orders.title"}]},templates:["/admin/order/template/order/list"],initialize:function(){this.render(),a.call(this)},render:function(){this.sandbox.dom.html(this.$el,this.renderTemplate("/admin/order/template/order/list")),this.sandbox.sulu.initListToolbarAndList.call(this,"ordersFields","/admin/api/orders/fields",{el:this.$find("#list-toolbar-container"),instanceName:"orders",inHeader:!0,template:b.call(this)},{el:this.sandbox.dom.find("#orders-list",this.$el),url:"/admin/api/orders?flat=true",searchInstanceName:"orders",searchFields:["fullName"],resultKey:"orders",viewOptions:{table:{selectItem:null,icons:[{icon:"pencil",column:"number",align:"left",callback:function(a){this.sandbox.emit("sulu.salesorder.orders.load",a)}.bind(this)}],highlightSelected:!0,fullWidth:!0}}})}}});