const {test,expect}=require('@playwright/test');
const fs=require('node:fs/promises');
const {execFileSync}=require('node:child_process');
// Accounts and passwords are synthetic and used only inside the isolated test DB.
const adminEmail='admin-e2e@example.test',initial='SyntheticAdmin123!',adminPassword='ChangedAdminSynthetic123!';
async function login(page,email,password,nextPassword){
  await page.goto('/');await expect(page.locator('#login-form')).toBeVisible();
  await page.locator('#login-form [name=email]').fill(email);await page.locator('#login-form [name=password]').fill(password);
  await page.locator('#login-form .primary').click();
  if(nextPassword){await expect(page.locator('#password-form')).toBeVisible();await page.locator('#password-form [name=currentPassword]').fill(password);await page.locator('#password-form [name=password]').fill(nextPassword);await page.locator('#password-form [name=confirm]').fill(nextPassword);await page.locator('#password-form .primary').click();}
  await expect(page.locator('#modal')).not.toBeVisible();await expect(page.locator('#connection')).toContainText('conectado');
}
async function register(page,email,name){
  await page.goto('/');await page.locator('#register').click();
  for(const [field,value] of Object.entries({name,email,password:'SyntheticClient123!',confirm:'SyntheticClient123!'}))await page.locator(`#register-form [name=${field}]`).fill(value);
  await page.locator('#register-form .primary').click();await expect(page.locator('#login-form')).toBeVisible();
  await login(page,email,'SyntheticClient123!');
}
async function api(page,path,method='GET',data){
  const session=await (await page.request.get('/index.php?api=session')).json();
  return page.request.fetch('/index.php?api='+encodeURIComponent(path),{method,data,headers:{'X-CSRF-Token':session.csrf}});
}
test('flujo UI completo, aislamiento, reportes y CRUD con motores reales',async({browser})=>{
  test.skip(process.env.OPENDESK_TEST_DATABASE!=='yes','Solo entorno explícitamente aislado');
  const hash=execFileSync('php',['-r',`echo password_hash('${initial}',PASSWORD_DEFAULT);`],{encoding:'utf8'});
  execFileSync('php',['index.php','--init-admin',adminEmail],{env:{...process.env,APP_PASSWORD_HASH:hash},stdio:'pipe'});
  const contexts=await Promise.all([0,1,2,3].map(()=>browser.newContext()));
  const [admin,client,agent,other]=await Promise.all(contexts.map(c=>c.newPage()));
  const subject='Reclamo sintético E2E '+Date.now();
  try{
    await login(admin,adminEmail,initial,adminPassword);
    await admin.locator('nav [data-view=users]').click();await admin.locator('#create').click();
    for(const [field,value] of Object.entries({name:'Agente sintético',email:'agent-e2e@example.test',password:'SyntheticAgent123!'}))await admin.locator(`#user-form [name=${field}]`).fill(value);
    await admin.locator('#user-form [name=role]').selectOption('agente');await admin.locator('#user-form .primary').click();
    await expect(admin.locator('#content')).toContainText('agent-e2e@example.test');
    await register(client,'client-e2e@example.test','Cliente sintético A');
    await expect(client.locator('nav [data-view=users]')).toBeHidden();
    await client.locator('nav [data-view=tickets]').click();await client.locator('#create').click();
    for(const [field,value] of Object.entries({subject,description:'Producto de ejemplo sin datos personales.'}))await client.locator(`#ticket-form [name=${field}]`).fill(value);
    await client.locator('#ticket-form [name=clientId]').selectOption({label:'Cliente sintético A'});await client.locator('#ticket-form [name=priority]').selectOption('Alta');await client.locator('#ticket-form [name=departmentId]').selectOption('reclamos');
    await client.locator('#ticket-form .primary').click();
    let row=client.locator('tbody tr').filter({hasText:subject});await expect(row).toBeVisible();await expect(row).toContainText('Pendiente de atención');
    const id=await row.locator('[data-ticket]').getAttribute('data-ticket');
    await register(other,'other-e2e@example.test','Cliente sintético B');
    await other.locator('nav [data-view=tickets]').click();await expect(other.locator('#content')).not.toContainText(subject);
    expect((await api(other,'tickets/'+id,'PATCH',{note:'No autorizado'})).status()).toBe(404);
    await admin.locator('nav [data-view=tickets]').click();await admin.locator('#sync').click();
    row=admin.locator('tbody tr').filter({hasText:subject});await expect(row).toBeVisible();await row.locator('[data-ticket]').click();
    await admin.locator('#suggest-assignment').click();await expect(admin.locator('#assignment-suggestion')).toContainText('Agente sintético');
    await admin.locator('#update-ticket [name=assigneeId]').selectOption({label:'Agente sintético'});await admin.locator('#update-ticket [name=status]').selectOption('En atención');
    await admin.locator('#update-ticket [name=note]').fill('Asignación de prueba');await admin.locator('#update-ticket .primary').click();await expect(row).toContainText('En proceso');
    await login(agent,'agent-e2e@example.test','SyntheticAgent123!','ChangedAgentSynthetic123!');
    await agent.locator('nav [data-view=tickets]').click();row=agent.locator('tbody tr').filter({hasText:subject});await expect(row).toBeVisible();
    await row.locator('[data-ticket]').click();await expect(agent.locator('#delete-ticket')).toHaveCount(0);
    await expect(agent.locator('#update-ticket [name=assigneeId]')).toHaveCount(0);
    await agent.locator('#internal-form [name=note]').fill('NOTA PRIVADA E2E');await agent.locator('#internal-form .secondary').click();
    expect(await (await api(client,'tickets/'+id)).text()).not.toContain('NOTA PRIVADA E2E');
    await row.locator('[data-ticket]').click();
    await agent.locator('#update-ticket [name=status]').selectOption('Solución propuesta');await agent.locator('#update-ticket [name=note]').fill('Solución sintética verificada.');await agent.locator('#update-ticket .primary').click();await expect(row).toContainText('Solución propuesta');
    expect((await api(agent,'users')).status()).toBe(403);
    await client.locator('#sync').click();await expect(client.locator('tbody tr').filter({hasText:subject})).toContainText('Solución propuesta');
    await client.locator('nav [data-view=notifications]').click();await expect(client.locator('#content')).toContainText('Solución sintética verificada.');
    await client.locator('nav [data-view=tickets]').click();await client.locator(`[data-ticket="${id}"]`).click();
    await expect(client.locator('#modal-content')).not.toContainText('NOTA PRIVADA E2E');
    await client.screenshot({path:'test-results/valoracion.png',fullPage:true});
    await client.locator('#rating-form [name=rating]').selectOption('5');await client.locator('#rating-form [name=comment]').fill('Excelente atención de prueba.');
    await client.locator('#rating-form .primary').click();await expect(client.locator('tbody tr').filter({hasText:subject})).toContainText('Cerrado');
    const rated=await (await api(client,'tickets/'+id)).json();expect(rated.rating).toBe(5);expect(rated.closedAt).toBeGreaterThan(0);
    await admin.locator('nav [data-view=teams]').click();await expect(admin.locator('#content')).toContainText('Agente sintético');
    await admin.screenshot({path:'test-results/equipos.png',fullPage:true});
    await client.screenshot({path:'test-results/ticket-resuelto.png',fullPage:true});
    await admin.locator('#sync').click();await admin.locator('nav [data-view=reports]').click();
    const download=admin.waitForEvent('download');await admin.locator('#export').click();const file=await download;expect(file.suggestedFilename()).toBe('opendesk-tickets.csv');await file.saveAs('test-results/reporte-sintetico.csv');
    const users=await (await api(admin,'users')).text();expect(users).not.toContain('passwordHash');
    await admin.locator('nav [data-view=tickets]').click();await admin.locator(`[data-ticket="${id}"]`).click();
    await admin.locator('#update-ticket [name=status]').selectOption('En atención');await admin.locator('#update-ticket [name=note]').fill('Reapertura sintética');await admin.locator('#update-ticket .primary').click();
    const all=await (await api(admin,'tickets')).json();expect(all.find(t=>t.id===id).resolvedAt).toBeUndefined();
    await admin.locator(`[data-ticket="${id}"]`).click();admin.once('dialog',d=>d.accept());await admin.locator('#delete-ticket').click();await expect(admin.locator('tbody tr').filter({hasText:subject})).toHaveCount(0);
    const operations={workflow:'assignment, internal notes, solution proposal, notifications, customer closure, rating and teams passed',flow:'passed',roles:'passed',isolation:'passed',csv:'passed',reopen:'passed',delete:'passed',environment:'isolated CI browser and real MongoDB Redis',notWebmin:true};
    await fs.writeFile('test-results/flow-evidence.json',JSON.stringify(operations,null,2));
  }finally{await Promise.all(contexts.map(c=>c.close()));}
});
