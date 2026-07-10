# ============================================================
# Indonesian Fish Classifier — FastAPI + PyTorch (CPU)
# Buat deploy ke Render / Railway / platform Docker apa pun.
# ============================================================
FROM python:3.10-slim

WORKDIR /app

# System lib buat Pillow (baca JPG/PNG)
RUN apt-get update && apt-get install -y --no-install-recommends \
        libjpeg62-turbo libpng16-16 \
    && rm -rf /var/lib/apt/lists/*

# PyTorch CPU-only. Wheel default bawa CUDA (~2GB) dan bakal gagal di free tier.
# CPU wheel jauh lebih kecil & cukup buat inference.
RUN pip install --no-cache-dir \
        torch==2.2.2 torchvision==0.17.2 \
        --index-url https://download.pytorch.org/whl/cpu

# Sisa dependency runtime
COPY requirements-web.txt .
RUN pip install --no-cache-dir -r requirements-web.txt

# Pre-cache bobot ImageNet EfficientNet-B0 ke dalam image, biar gak
# download pas startup (lebih cepat + gak gagal kalau jaringan lambat).
ENV TORCH_HOME=/app/.torch
RUN python -c "import torchvision.models as m; m.efficientnet_b0(weights=m.EfficientNet_B0_Weights.IMAGENET1K_V1)"

# Kode + model + frontend
COPY src ./src
COPY app ./app
COPY models ./models

# Render/Railway inject $PORT sendiri; 8000 cuma fallback lokal.
ENV PORT=8000
EXPOSE 8000

CMD ["sh", "-c", "uvicorn app.backend.main:app --host 0.0.0.0 --port ${PORT}"]
