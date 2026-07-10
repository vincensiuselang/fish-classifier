// Frontend disajikan langsung oleh FastAPI -> pakai same-origin ("").
// Kalau dibuka via file:// atau live-server terpisah (bukan port 8000), tembak ke :8000.
const LOCAL_API = "http://localhost:8000";
const host = window.location.hostname;
const isSeparateLocal =
    window.location.protocol === "file:" ||
    ((host === "localhost" || host === "127.0.0.1") && window.location.port !== "8000");
const API_URL = isSeparateLocal ? LOCAL_API : "";

const drop = document.getElementById("drop");
const fileInput = document.getElementById("file-input");
const dropInner = document.getElementById("dropInner");
const preview = document.getElementById("preview");
const predictBtn = document.getElementById("predict-btn");
const resetBtn = document.getElementById("reset-btn");
const modelSelect = document.getElementById("model-select");
const resultSection = document.getElementById("result-section");
const predictionName = document.getElementById("prediction-name");
const confidenceText = document.getElementById("confidence-text");
const topKList = document.getElementById("top-k-list");
const meta = document.getElementById("meta");
const statusBox = document.getElementById("status");

let currentFile = null;

drop.addEventListener("click", () => fileInput.click());
fileInput.addEventListener("change", (e) => {
    if (e.target.files.length > 0) handleFile(e.target.files[0]);
});

drop.addEventListener("dragover", (e) => {
    e.preventDefault();
    drop.classList.add("drag");
});
drop.addEventListener("dragleave", () => drop.classList.remove("drag"));
drop.addEventListener("drop", (e) => {
    e.preventDefault();
    drop.classList.remove("drag");
    if (e.dataTransfer.files.length > 0) handleFile(e.dataTransfer.files[0]);
});

resetBtn.addEventListener("click", (e) => {
    e.stopPropagation();
    currentFile = null;
    fileInput.value = "";
    preview.hidden = true;
    dropInner.hidden = false;
    resetBtn.hidden = true;
    predictBtn.disabled = true;
    resultSection.hidden = true;
    hideStatus();
});

function handleFile(file) {
    if (!file.type.startsWith("image/")) {
        showStatus("File harus berupa gambar (JPG/PNG/WEBP)", true);
        return;
    }
    currentFile = file;
    hideStatus();
    resultSection.hidden = true;

    const reader = new FileReader();
    reader.onload = (e) => {
        preview.src = e.target.result;
        preview.hidden = false;
        dropInner.hidden = true;
    };
    reader.readAsDataURL(file);

    predictBtn.disabled = false;
    resetBtn.hidden = false;
}

predictBtn.addEventListener("click", async () => {
    if (!currentFile) return;

    const formData = new FormData();
    formData.append("file", currentFile);

    showStatus('<span class="spin"></span>PROCESSING // model lagi mikir...', false);
    resultSection.hidden = true;
    predictBtn.disabled = true;

    try {
        const model = modelSelect.value;
        const response = await fetch(`${API_URL}/predict?model=${model}`, {
            method: "POST",
            body: formData,
        });

        if (!response.ok) {
            const err = await response.json().catch(() => ({ detail: "Server error" }));
            throw new Error(err.detail || `HTTP ${response.status}`);
        }

        const data = await response.json();
        renderResult(data);
        hideStatus();
    } catch (err) {
        showStatus(`ERROR // ${err.message}`, true);
    } finally {
        predictBtn.disabled = false;
    }
});

function renderResult(data) {
    const confPct = (data.confidence * 100).toFixed(2);

    if (data.is_confident === false) {
        predictionName.textContent = `TIDAK YAKIN (mirip: ${data.raw_prediction})`;
        predictionName.style.color = "var(--orange)";
    } else {
        predictionName.textContent = data.prediction;
        predictionName.style.color = "";
    }

    confidenceText.textContent = `CONFIDENCE: ${confPct}%`;

    topKList.innerHTML = "";
    data.top_k.forEach((item) => {
        const pct = (item.confidence * 100).toFixed(2);
        const row = document.createElement("div");
        row.className = "bar-row";
        row.innerHTML = `
            <span class="bar-name">${item.class}</span>
            <span class="bar-track"><span class="bar-fill" style="width:${pct}%"></span></span>
            <span class="bar-val">${pct}%</span>
        `;
        topKList.appendChild(row);
    });

    meta.textContent = `model: ${modelSelect.value} // engine: pytorch cpu`;
    resultSection.hidden = false;
}

function showStatus(html, isErr) {
    statusBox.innerHTML = html;
    statusBox.classList.toggle("err", !!isErr);
    statusBox.hidden = false;
}
function hideStatus() {
    statusBox.hidden = true;
    statusBox.innerHTML = "";
}
