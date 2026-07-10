"""
CNN from scratch v2 - arsitektur lebih dalam buat klasifikasi ikan.
Pattern VGG-style: 5 blocks (double conv di blok tengah), GAP, terus Dense + Dropout.

Upgrade dari v1:
- 4 blok single-conv (32-64-128-256)  ->  5 blok, double conv di blok 2-4 (32-64-128-256-512)
- Kapasitas naik ~6x (390K -> ~2.5M params), receptive field lebih besar
- Classifier lebih lebar: 512 -> 256 -> num_classes
"""
import torch
import torch.nn as nn

from src import config


def conv_bn_relu(in_c: int, out_c: int) -> list:
    """Satu unit Conv3x3-BN-ReLU (kernel 3, pad 1, stride 1)."""
    return [
        nn.Conv2d(in_c, out_c, kernel_size=3, padding=1),
        nn.BatchNorm2d(out_c),
        nn.ReLU(inplace=True),
    ]


class FishCNN(nn.Module):
    """
    Custom CNN arsitektur v2:
    - Block 1: 1x conv  (3 -> 32),   pool  224 -> 112
    - Block 2: 2x conv  (32 -> 64),  pool  112 -> 56
    - Block 3: 2x conv  (64 -> 128), pool  56  -> 28
    - Block 4: 2x conv  (128 -> 256),pool  28  -> 14
    - Block 5: 1x conv  (256 -> 512),pool  14  -> 7
    - GlobalAvgPool -> 512
    - Dropout(0.5) -> Linear(512, 256) -> ReLU -> Dropout(0.3) -> Linear(256, num_classes)
    """

    def __init__(self, num_classes: int = config.NUM_CLASSES):
        super().__init__()

        layers = []
        # Block 1: 224 -> 112
        layers += conv_bn_relu(3, 32)
        layers += [nn.MaxPool2d(2)]
        # Block 2 (double conv): 112 -> 56
        layers += conv_bn_relu(32, 64)
        layers += conv_bn_relu(64, 64)
        layers += [nn.MaxPool2d(2)]
        # Block 3 (double conv): 56 -> 28
        layers += conv_bn_relu(64, 128)
        layers += conv_bn_relu(128, 128)
        layers += [nn.MaxPool2d(2)]
        # Block 4 (double conv): 28 -> 14
        layers += conv_bn_relu(128, 256)
        layers += conv_bn_relu(256, 256)
        layers += [nn.MaxPool2d(2)]
        # Block 5: 14 -> 7
        layers += conv_bn_relu(256, 512)
        layers += [nn.MaxPool2d(2)]

        self.features = nn.Sequential(*layers)

        # Global Average Pooling -> spatial jadi 1x1
        self.global_pool = nn.AdaptiveAvgPool2d(1)

        self.classifier = nn.Sequential(
            nn.Flatten(),
            nn.Dropout(0.5),
            nn.Linear(512, 256),
            nn.ReLU(inplace=True),
            nn.Dropout(0.3),
            nn.Linear(256, num_classes),
        )

        self._init_weights()

    def _init_weights(self):
        """Kaiming init buat conv, penting biar training deep net dari scratch stabil."""
        for m in self.modules():
            if isinstance(m, nn.Conv2d):
                nn.init.kaiming_normal_(m.weight, mode="fan_out", nonlinearity="relu")
                if m.bias is not None:
                    nn.init.zeros_(m.bias)
            elif isinstance(m, nn.BatchNorm2d):
                nn.init.ones_(m.weight)
                nn.init.zeros_(m.bias)
            elif isinstance(m, nn.Linear):
                nn.init.normal_(m.weight, 0, 0.01)
                nn.init.zeros_(m.bias)

    def forward(self, x):
        x = self.features(x)
        x = self.global_pool(x)
        x = self.classifier(x)
        return x


def build_cnn_scratch(num_classes: int = config.NUM_CLASSES) -> nn.Module:
    """Factory function buat consistency sama transfer_learning.py"""
    return FishCNN(num_classes=num_classes)


if __name__ == "__main__":
    model = build_cnn_scratch()
    x = torch.randn(2, 3, 224, 224)
    out = model(x)
    print(f"Output shape: {out.shape}")
    n_params = sum(p.numel() for p in model.parameters() if p.requires_grad)
    print(f"Trainable params: {n_params:,}")
