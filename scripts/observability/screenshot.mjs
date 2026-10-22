// Optional local visual evidence using an installed Chromium browser. No extra Grafana plugin.
import {spawn} from 'node:child_process';
import {readFile, writeFile, mkdtemp, rm} from 'node:fs/promises';
import {tmpdir} from 'node:os';
import {join} from 'node:path';
import {setTimeout as pause} from 'node:timers/promises';

const [envPath, url, output] = process.argv.slice(2);
const values = Object.fromEntries((await readFile(envPath, 'utf8')).trim().split('\n').map(line => {
  const i = line.indexOf('='); return [line.slice(0, i), line.slice(i + 1)];
}));
const directory = await mkdtemp(join(tmpdir(), 'devops-obs-browser-'));
const browser = spawn(process.env.OBS_BROWSER || '/opt/brave.com/brave/brave', [
  '--headless', '--no-sandbox', '--disable-gpu', '--disable-dev-shm-usage', '--no-first-run',
  '--disable-background-networking', '--disable-extensions', '--remote-debugging-port=0',
  '--user-data-dir=' + directory, 'about:blank',
], {stdio: 'ignore'});
let websocket;
try {
  let port;
  for (let i = 0; i < 100; i++) {
    try { port = (await readFile(join(directory, 'DevToolsActivePort'), 'utf8')).split('\n')[0]; break; } catch { await pause(100); }
  }
  if (!port) throw new Error('Chromium did not start');
  const target = await (await fetch(`http://127.0.0.1:${port}/json/new?about:blank`, {method: 'PUT'})).json();
  websocket = new WebSocket(target.webSocketDebuggerUrl);
  await new Promise(resolve => websocket.addEventListener('open', resolve, {once:true}));
  let sequence = 0;
  const waiting = new Map();
  websocket.addEventListener('message', event => {
    const data = JSON.parse(event.data);
    if (waiting.has(data.id)) { const {resolve,reject} = waiting.get(data.id); waiting.delete(data.id); data.error ? reject(new Error(data.error.message)) : resolve(data.result); }
  });
  function send(method, params = {}) {
    const id = ++sequence;
    return new Promise((resolve,reject) => { waiting.set(id,{resolve,reject}); websocket.send(JSON.stringify({id,method,params})); });
  }
  await send('Page.enable');
  await send('Emulation.setDeviceMetricsOverride', {width:1600,height:2400,deviceScaleFactor:1,mobile:false});
  await send('Page.navigate', {url:url + '/login'});
  await pause(1500);
  // Credentials remain in the local DevTools session and are never written to output.
  const login = await send('Runtime.evaluate', {expression:`(async()=>{const r=await fetch('/login',{method:'POST',headers:{'Content-Type':'application/json'},body:JSON.stringify({user:'admin',password:${JSON.stringify(values.GRAFANA_ADMIN_PASSWORD)}})});return r.status})()`,awaitPromise:true,returnByValue:true});
  if (login.result.value !== 200) throw new Error('Grafana login failed');
  await send('Page.navigate',{url:url + '/d/devops-api?from=now-15m&to=now&kiosk'});
  await pause(12000);
  await send('Runtime.evaluate',{expression:'window.scrollTo(0,0)'});
  await pause(1000);
  const content = await send('Runtime.evaluate',{expression:'document.body.innerText',returnByValue:true});
  if (!content.result.value.includes('Solicitudes por segundo') || content.result.value.includes('Sign in to Grafana')) throw new Error('Dashboard did not render');
  await send('Page.bringToFront');
  const screenshot = await send('Page.captureScreenshot',{format:'png',captureBeyondViewport:false,fromSurface:true});
  await writeFile(output, Buffer.from(screenshot.data,'base64'));
  console.log('PASS authenticated dashboard screenshot: ' + output);
} finally {
  websocket?.close();
  if (browser.exitCode === null) {
    browser.kill('SIGTERM');
    await new Promise(resolve => browser.once('exit', resolve));
  }
  await rm(directory,{recursive:true,force:true,maxRetries:5,retryDelay:200});
}
