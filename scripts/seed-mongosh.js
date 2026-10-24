// mongosh --nodb --file scripts/seed-mongosh.js; URI solo desde el entorno privado.
const uri=process.env.MONGODB_URI, name=process.env.MONGODB_DATABASE;
if(!uri||!name||!/^[a-zA-Z0-9_-]+$/.test(name))throw Error('Configure MONGODB_URI y MONGODB_DATABASE en su entorno privado.');
const database=connect(uri).getSiblingDB(name), now=Math.floor(Date.now()/1000);
for(const name of ['odac_clients','odac_products','odac_tickets'])database.getCollection(name).createIndex({id:1},{unique:true});
const client={id:'mongosh-demo-client',name:'Cliente de demostracion mongosh',email:'mongosh.demo@example.test',phone:'',company:'',createdAt:now};
database.odac_clients.updateOne({id:client.id},{$setOnInsert:client},{upsert:true});
const departments=['ventas','garantias','entregas','reclamos'];
for(const [i,departmentId] of departments.entries()){
 const id='mongosh-demo-ticket-'+(i+1), category=['Consulta','Garantía','Problema con pedido','Reclamo'][i];
 const ticket={id,clientId:client.id,requester:client.name,subject:'Demostracion de '+departmentId,description:'Caso sintetico reproducible sin datos personales reales.',productId:'',category,orderRef:'MONGOSH-DEMO',priority:'Media',status:'Nuevo',departmentId,assigneeId:'',assignee:'',waitingFor:'agente',createdAt:now,lastActivityAt:now,dueAt:now+86400,history:[{date:now,text:'Carga sintetica con mongosh.'}]};
 database.odac_tickets.updateOne({id},{$setOnInsert:ticket},{upsert:true});
}
print(JSON.stringify({tool:'mongosh',tickets:database.odac_tickets.countDocuments({id:{$in:[1,2,3,4].map(i=>'mongosh-demo-ticket-'+i)}}),idempotent:true}));
