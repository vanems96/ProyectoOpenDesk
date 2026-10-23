const {defineConfig}=require('@playwright/test');
module.exports=defineConfig({
  testDir:'tests/browser',workers:1,retries:0,timeout:120000,
  use:{baseURL:process.env.E2E_BASE_URL||'http://127.0.0.1:8080',browserName:'chromium',viewport:{width:1366,height:900},trace:'retain-on-failure',screenshot:'only-on-failure'},
  reporter:[['list'],['json',{outputFile:'test-results/browser-results.json'}]],
});
