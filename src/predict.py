"""
Single-image inference module. Dipake langsung di CLI atau di-import sama API.

Yang baru:
- Baca backbone dari checkpoint (biar arsitektur match, gak hardcode).
- Confidence threshold: kalau prediksi teratas < config.CONFIDENCE_THRESHOLD,
  hasil ditandai "Tidak yakin" (is_confident=False). Berguna buat foto
  out-of-distribution (Google/bukan ikan) biar gak asal nembak 1 kelas.
- TTA (test-time augmentation) opsional: rata-rata prediksi dari beberapa
  variasi (original + horizontal flip) -> prediksi lebih stabil.

Usage:
    python -m src.predict --image path/to/fish.jpg --model transfer
    python -m src.predict --image fish.jpg --no-tta
"""
import argparse
from pathlib import Path

import torch
import torch.nn.functional as F
from PIL import Image

from src import config
from src.data_loader import get_transforms
from src.models.cnn_scratch import build_cnn_scratch
from src.models.transfer_learning import build_transfer_model


_MODEL_CACHE = {}  # cache biar API gak load model tiap request


def load_model(model_name: str = config.DEFAULT_MODEL):
    """Load model dari checkpoint, dengan caching. Backbone diambil dari checkpoint."""
    if model_name in _MODEL_CACHE:
        return _MODEL_CACHE[model_name]

    if model_name == "cnn_scratch":
        ckpt_path = config.CNN_SCRATCH_PATH
    elif model_name == "transfer":
        ckpt_path = config.TRANSFER_PATH
    else:
        raise ValueError(f"Model '{model_name}' gak dikenal")

    if not Path(ckpt_path).exists():
        raise FileNotFoundError(
            f"Checkpoint gak ada: {ckpt_path}. Train dulu: python -m src.train --model {model_name}"
        )

    ckpt = torch.load(ckpt_path, map_location=config.DEVICE)

    if model_name == "cnn_scratch":
        model = build_cnn_scratch()
    else:
        # Backbone diambil dari checkpoint biar arsitektur cocok sama bobot
        backbone = ckpt.get("backbone", config.BACKBONE)
        model = build_transfer_model(freeze_backbone=False, backbone=backbone)

    model.load_state_dict(ckpt["model_state"])
    model = model.to(config.DEVICE)
    model.eval()

    idx_to_class = {v: k for k, v in ckpt["class_to_idx"].items()}
    _MODEL_CACHE[model_name] = (model, idx_to_class)
    return model, idx_to_class


@torch.no_grad()
def _forward_probs(model, tensor, use_tta: bool):
    """Return prob rata-rata (TTA: original + horizontal flip)."""
    logits = model(tensor)
    probs = F.softmax(logits, dim=1)
    if use_tta:
        logits_flip = model(torch.flip(tensor, dims=[3]))  # flip horizontal
        probs = (probs + F.softmax(logits_flip, dim=1)) / 2.0
    return probs.squeeze(0).cpu().numpy()


@torch.no_grad()
def predict_image(
    image: Image.Image,
    model_name: str = config.DEFAULT_MODEL,
    top_k: int = 3,
    use_tta: bool = None,
):
    """
    Predict satu gambar.

    Returns dict: prediction, confidence, is_confident, top_k, model.
    Kalau confidence < threshold -> is_confident=False & prediction diberi note.
    """
    if use_tta is None:
        use_tta = config.USE_TTA

    model, idx_to_class = load_model(model_name)

    if image.mode != "RGB":
        image = image.convert("RGB")
    transform = get_transforms(train=False)
    tensor = transform(image).unsqueeze(0).to(config.DEVICE)

    probs = _forward_probs(model, tensor, use_tta)

    top_indices = probs.argsort()[::-1][:top_k]
    top_results = [
        {"class": idx_to_class[int(i)], "confidence": float(probs[i])}
        for i in top_indices
    ]

    best_idx = int(top_indices[0])
    best_conf = float(probs[best_idx])
    is_confident = best_conf >= config.CONFIDENCE_THRESHOLD

    return {
        "prediction": idx_to_class[best_idx] if is_confident else "Tidak yakin",
        "raw_prediction": idx_to_class[best_idx],
        "confidence": best_conf,
        "is_confident": is_confident,
        "threshold": config.CONFIDENCE_THRESHOLD,
        "top_k": top_results,
        "model": model_name,
    }


if __name__ == "__main__":
    parser = argparse.ArgumentParser()
    parser.add_argument("--image", required=True, help="Path ke gambar")
    parser.add_argument("--model", choices=["cnn_scratch", "transfer"], default="transfer")
    parser.add_argument("--no-tta", action="store_true", help="Matikan TTA")
    args = parser.parse_args()

    img = Image.open(args.image)
    result = predict_image(img, model_name=args.model, use_tta=not args.no_tta)
    tag = "" if result["is_confident"] else "  (< threshold, kemungkinan bukan ikan yg dikenal)"
    print(f"\nPrediction: {result['prediction']} ({result['confidence']*100:.2f}%){tag}")
    print("Top-3:")
    for r in result["top_k"]:
        print(f"  {r['class']}: {r['confidence']*100:.2f}%")
