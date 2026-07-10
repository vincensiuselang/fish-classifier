"""
FastAPI backend untuk Indonesian Fish Classifier.
Run dari root project:
    uvicorn app.backend.main:app --reload --host 0.0.0.0 --port 8000

Endpoints:
    GET  /              -> health check + info
    GET  /classes       -> list nama kelas
    POST /predict       -> upload gambar, return prediksi
    GET  /docs          -> Swagger UI (auto-generated)
"""
import io
import sys
from pathlib import Path

# Bikin src bisa di-import (kalau jalanin dari root project)
ROOT = Path(__file__).resolve().parent.parent.parent
sys.path.insert(0, str(ROOT))

from fastapi import FastAPI, File, UploadFile, HTTPException, Query
from fastapi.middleware.cors import CORSMiddleware
from fastapi.responses import JSONResponse, FileResponse
from fastapi.staticfiles import StaticFiles
from PIL import Image

from src import config
from src.predict import predict_image, load_model

FRONTEND_DIR = ROOT / "app" / "frontend"

app = FastAPI(
    title="Indonesian Fish Classifier API",
    description="Klasifikasi 5 jenis ikan Indonesia: Bawal Putih, Nila, Pari, Tongkol, Tuna",
    version="1.0.0",
)

# CORS - biar frontend (port lain) bisa akses
app.add_middleware(
    CORSMiddleware,
    allow_origins=["*"],  # Production: ganti ke domain frontend lo
    allow_credentials=True,
    allow_methods=["*"],
    allow_headers=["*"],
)


@app.on_event("startup")
async def warmup():
    """Pre-load model biar request pertama gak lambat."""
    try:
        load_model(config.DEFAULT_MODEL)
        print(f"✓ Model '{config.DEFAULT_MODEL}' loaded successfully")
    except FileNotFoundError as e:
        print(f"⚠ Warning: {e}")
        print("  API tetap jalan, tapi /predict bakal error sampai model di-train.")


@app.get("/")
def root():
    """Sajikan halaman frontend (kalau ada), fallback ke info JSON."""
    index_file = FRONTEND_DIR / "index.html"
    if index_file.exists():
        return FileResponse(index_file)
    return {
        "service": "Indonesian Fish Classifier",
        "status": "ok",
        "endpoints": ["/predict", "/classes", "/api", "/docs"],
    }


@app.get("/api")
def api_info():
    return {
        "service": "Indonesian Fish Classifier",
        "status": "ok",
        "device": str(config.DEVICE),
        "default_model": config.DEFAULT_MODEL,
        "classes": config.CLASS_NAMES,
        "endpoints": ["/predict", "/classes", "/docs"],
    }


@app.get("/classes")
def get_classes():
    return {"classes": config.CLASS_NAMES, "num_classes": config.NUM_CLASSES}


@app.post("/predict")
async def predict(
    file: UploadFile = File(..., description="Image file (jpg/png)"),
    model: str = Query(config.DEFAULT_MODEL, regex="^(transfer|cnn_scratch)$"),
):
    """
    Upload gambar ikan, return prediksi + top-3 confidence.
    """
    # Validasi file
    if not file.content_type or not file.content_type.startswith("image/"):
        raise HTTPException(status_code=400, detail="File harus berupa gambar (jpg/png)")

    try:
        contents = await file.read()
        image = Image.open(io.BytesIO(contents))
    except Exception as e:
        raise HTTPException(status_code=400, detail=f"Gagal baca gambar: {e}")

    try:
        result = predict_image(image, model_name=model, top_k=3)
    except FileNotFoundError as e:
        raise HTTPException(status_code=503, detail=str(e))
    except Exception as e:
        raise HTTPException(status_code=500, detail=f"Inference error: {e}")

    return JSONResponse(content=result)


# Sajikan aset frontend (style.css, script.js) di /static.
# Ditaruh paling bawah biar gak nabrak route API di atas.
if FRONTEND_DIR.exists():
    app.mount("/static", StaticFiles(directory=str(FRONTEND_DIR)), name="static")


if __name__ == "__main__":
    import uvicorn
    uvicorn.run(
        "app.backend.main:app",
        host=config.API_HOST,
        port=config.API_PORT,
        reload=True,
    )
