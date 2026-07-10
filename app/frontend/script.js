// Konfigurasi backend.
// Frontend disajikan langsung oleh FastAPI, jadi default pakai same-origin ("").
// Kalau dibuka via file:// atau live-server terpisah (bukan port 8000), tembak ke :8000.
const LOCAL_API = "http://localhost:8000";
const host = window.location.hostname;
const isSeparateLocal =
    window.location.protocol === "file:" ||
    ((host === "localhost" || host === "127.0.0.1") && window.location.port !== "8000");
const API_URL = isSeparateLocal ? LOCAL_API : "";

const uploadArea = document.getElementById("upload-area");
const fileInput = document.getElementById("file-input");
const uploadContent = document.getElementById("upload-content");
const preview = document.getElementById("preview");
const predictBtn = document.getElementById("predict-btn");
const modelSelect = document.getElementById("model-select");
const resultSection = document.getElementById("result-section");
const predictionName = document.getElementById("prediction-name");
const confidenceFill = document.getElementById("confidence-fill");
const confidenceText = document.getElementById("confidence-text");
const topKList = document.getElementById("top-k-list");
const loading = document.getElementById("loading");
const errorBox = document.getElementById("error");

let currentFile = null;

// === Click upload area buat trigger file picker ===
uploadArea.addEventListener("click", () => fileInput.click());

// === File select via input ===
fileInput.addEventListener("change", (e) => {
    if (e.target.files.length > 0) handleFile(e.target.files[0]);
});

// === Drag & drop ===
uploadArea.addEventListener("dragover", (e) => {
    e.preventDefault();
    uploadArea.classList.add("dragover");
});

uploadArea.addEventListener("dragleave", () => {
    uploadArea.classList.remove("dragover");
});

uploadArea.addEventListener("drop", (e) => {
    e.preventDefault();
    uploadArea.classList.remove("dragover");
    if (e.dataTransfer.files.length > 0) handleFile(e.dataTransfer.files[0]);
});

function handleFile(file) {
    if (!file.type.startsWith("image/")) {
        showError("File harus berupa gambar (JPG/PNG)");
        return;
    }
    currentFile = file;
    hideError();
    resultSection.hidden = true;

    // Preview
    const reader = new FileReader();
    reader.onload = (e) => {
        preview.src = e.target.result;
        preview.hidden = false;
        uploadContent.hidden = true;
    };
    reader.readAsDataURL(file);

    predictBtn.disabled = false;
}

// === Predict button ===
predictBtn.addEventListener("click", async () => {
    if (!currentFile) return;

    const formData = new FormData();
    formData.append("file", currentFile);

    loading.hidden = false;
    resultSection.hidden = true;
    hideError();
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
    } catch (err) {
        showError(`Gagal prediksi: ${err.message}`);
    } finally {
        loading.hidden = true;
        predictBtn.disabled = false;
    }
});

function renderResult(data) {
    const confPct = (data.confidence * 100).toFixed(2);

    // Kalau model gak yakin (confidence < threshold), kasih tau + tebakan terdekatnya
    if (data.is_confident === false) {
        predictionName.textContent = `Tidak yakin (mirip: ${data.raw_prediction})`;
        predictionName.style.color = "#e67e22";
    } else {
        predictionName.textContent = data.prediction;
        predictionName.style.color = "";
    }

    confidenceText.textContent = `${confPct}%`;
    confidenceFill.style.width = `${confPct}%`;

    topKList.innerHTML = "";
    data.top_k.forEach((item) => {
        const div = document.createElement("div");
        div.className = "top-k-item";
        div.innerHTML = `
            <span class="name">${item.class}</span>
            <span class="pct">${(item.confidence * 100).toFixed(2)}%</span>
        `;
        topKList.appendChild(div);
    });

    resultSection.hidden = false;
}

function showError(msg) {
    errorBox.textContent = msg;
    errorBox.hidden = false;
}

function hideError() {
    errorBox.hidden = true;
    errorBox.textContent = "";
}
