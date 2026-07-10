const drop = document.getElementById('drop');
const fileInput = document.getElementById('file');
const preview = document.getElementById('preview');
const dropInner = document.getElementById('dropInner');
const btn = document.getElementById('btn');
const resetBtn = document.getElementById('reset');
const statusEl = document.getElementById('status');
const resultEl = document.getElementById('result');
const savedTag = document.getElementById('savedTag');
let currentFile = null;

drop.addEventListener('click', () => fileInput.click());
['dragover','dragenter'].forEach(ev => drop.addEventListener(ev, e => {
  e.preventDefault(); drop.classList.add('drag');
}));
['dragleave','drop'].forEach(ev => drop.addEventListener(ev, e => {
  e.preventDefault(); drop.classList.remove('drag');
}));
drop.addEventListener('drop', e => { if (e.dataTransfer.files[0]) setFile(e.dataTransfer.files[0]); });
fileInput.addEventListener('change', () => { if (fileInput.files[0]) setFile(fileInput.files[0]); });

function setFile(f) {
  if (!f.type.startsWith('image/')) { showError('File harus berupa gambar.'); return; }
  currentFile = f;
  const url = URL.createObjectURL(f);
  preview.src = url; preview.hidden = false; dropInner.hidden = true;
  btn.disabled = false; resetBtn.hidden = false;
  resultEl.hidden = true; statusEl.hidden = true;
}

resetBtn.addEventListener('click', () => {
  currentFile = null; fileInput.value = '';
  preview.hidden = true; preview.src = ''; dropInner.hidden = false;
  btn.disabled = true; resetBtn.hidden = true;
  resultEl.hidden = true; statusEl.hidden = true;
});

btn.addEventListener('click', predict);

async function predict() {
  if (!currentFile) return;
  btn.disabled = true;
  resultEl.hidden = true;
  showStatus('<span class="spin"></span>PROCESSING... forward-pass CNN jalan di server.'
    + '<div class="prog-wrap"><div class="prog"><div class="prog-fill" id="progFill"></div></div>'
    + '<span class="prog-pct" id="progPct">0%</span></div>');
  startProgress();
  const fd = new FormData();
  fd.append('file', currentFile);
  try {
    const res = await fetch('predict.php', { method: 'POST', body: fd });
    const data = await res.json();
    if (!res.ok) { stopProgress(); showError(data.error || 'Gagal memproses.'); btn.disabled = false; return; }
    await finishProgress();
    render(data);
  } catch (err) {
    stopProgress();
    showError('Gagal konek ke server: ' + err.message);
  }
  btn.disabled = false;
}

/* ---- progress bar simulasi (server gak kirim progress real) ---- */
let progTimer = null, progVal = 0;
function setProg(v) {
  progVal = v;
  const f = document.getElementById('progFill');
  const p = document.getElementById('progPct');
  if (f) f.style.width = v + '%';
  if (p) p.textContent = Math.round(v) + '%';
}
function startProgress() {
  progVal = 0; setProg(0);
  clearInterval(progTimer);
  // naik cepat di awal, makin pelan mendekati 90% (nungguin response beneran)
  progTimer = setInterval(() => {
    if (progVal < 90) setProg(Math.min(90, progVal + Math.max(0.6, (90 - progVal) * 0.09)));
  }, 110);
}
function stopProgress() { clearInterval(progTimer); progTimer = null; }
function finishProgress() {
  stopProgress(); setProg(100);
  return new Promise(r => setTimeout(r, 250)); // biar keliatan penuh sebentar
}

function render(d) {
  statusEl.hidden = true;
  document.getElementById('topClass').textContent = d.prediction;
  document.getElementById('topConf').textContent = (d.confidence * 100).toFixed(1) + '% yakin';
  savedTag.textContent = d.saved_to_db ? '[tersimpan]' : '[tak tersimpan]';
  const bars = document.getElementById('bars');
  bars.innerHTML = '';
  d.top_k.forEach(item => {
    const pct = (item.confidence * 100).toFixed(1);
    const row = document.createElement('div');
    row.className = 'bar-row';
    row.innerHTML = `<div class="bar-name">${item.class}</div>
      <div class="bar-track"><div class="bar-fill"></div></div>
      <div class="bar-val">${pct}%</div>`;
    bars.appendChild(row);
    requestAnimationFrame(() => row.querySelector('.bar-fill').style.width = pct + '%');
  });
  document.getElementById('meta').textContent =
    `model: ${d.model}  ·  ${d.input_size}px  ·  inference ${d.elapsed_ms} ms  ·  db: ${d.saved_to_db ? 'ok' : 'skip'}`;
  resultEl.hidden = false;
}

function showStatus(html){ statusEl.className='status'; statusEl.innerHTML=html; statusEl.hidden=false; }
function showError(msg){ statusEl.className='status err'; statusEl.textContent='! '+msg; statusEl.hidden=false; }
