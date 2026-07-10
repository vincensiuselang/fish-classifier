"""
Export bobot PyTorch (.pth) -> .bin + .json manifest buat inference PHP.
Tanpa dependensi torch (parse pickle manual).

Usage (dari root project):
    python fish-classifier-php/tools/export_weights.py
    python fish-classifier-php/tools/export_weights.py --pth models/cnn_scratch_best.pth --out fish-classifier-php/weights
"""
import argparse, io, json, pickle, zipfile, os
from collections import OrderedDict
import numpy as np

_HERE = os.path.dirname(os.path.abspath(__file__))
_ROOT = os.path.dirname(os.path.dirname(_HERE))  # root project (PROJECT PWL)

parser = argparse.ArgumentParser()
parser.add_argument("--pth", default=os.path.join(_ROOT, "models", "cnn_scratch_best.pth"))
parser.add_argument("--out", default=os.path.join(os.path.dirname(_HERE), "weights"))
args = parser.parse_args()

PTH = args.pth
OUT_DIR = args.out
os.makedirs(OUT_DIR, exist_ok=True)

DTYPE = {"FloatStorage":np.float32,"DoubleStorage":np.float64,"LongStorage":np.int64,
         "IntStorage":np.int32,"HalfStorage":np.float16,"ByteStorage":np.uint8}

class SM:
    def __init__(self, key, dtype): self.key=key; self.dtype=dtype

class U(pickle.Unpickler):
    def find_class(self, module, name):
        if module=="torch._utils" and name=="_rebuild_tensor_v2":
            def rb(storage,offset,size,stride,requires_grad=False,backward_hooks=None,metadata=None):
                return {"storage":storage,"offset":offset,"size":tuple(size),"stride":tuple(stride)}
            return rb
        if module=="torch._utils" and name=="_rebuild_parameter":
            return lambda data,*a,**k: data
        if module=="torch" and name in DTYPE: return name
        if module=="collections" and name=="OrderedDict": return OrderedDict
        try: return super().find_class(module,name)
        except Exception: return lambda *a,**k: None
    def persistent_load(self, pid):
        typ=pid[1]; key=pid[2]
        dn=typ if isinstance(typ,str) else getattr(typ,"__name__","FloatStorage")
        return SM(str(key), DTYPE.get(dn,np.float32))

with zipfile.ZipFile(PTH) as zf:
    arch=zf.namelist()[0].split("/")[0]
    try: bo=zf.read(f"{arch}/byteorder").decode().strip()
    except KeyError: bo="little"
    ckpt=U(io.BytesIO(zf.read(f"{arch}/data.pkl"))).load()
    def rt(d):
        sm=d["storage"]; raw=zf.read(f"{arch}/data/{sm.key}")
        a=np.frombuffer(raw,dtype=sm.dtype)
        if bo=="big": a=a.byteswap()
        size=d["size"]; off=d["offset"]; n=int(np.prod(size)) if size else 1
        return np.ascontiguousarray((a[off:off+n].reshape(size) if size else a[off:off+1]))
    state=ckpt["model_state"]; c2i=ckpt.get("class_to_idx",{})
    print("ckpt keys:",list(ckpt.keys()),"| val_acc:",ckpt.get("val_acc"),"| class_to_idx:",c2i)
    W={}
    for k,v in state.items():
        W[k]= rt(v).astype(np.float32) if (isinstance(v,dict) and "storage" in v) else v
    for k in state:
        if hasattr(W.get(k),"shape"): print(f"  {k:45s}{tuple(W[k].shape)}")

man={"class_to_idx":c2i,"idx_to_class":{v:k for k,v in c2i.items()},
     "val_acc":float(ckpt.get("val_acc",0)),"params":{}}
buf=io.BytesIO(); off=0
for k,v in W.items():
    if not hasattr(v,"shape"): continue
    flat=v.astype("<f4").ravel(); b=flat.tobytes()
    man["params"][k]={"offset":off,"count":int(flat.size),"shape":list(v.shape)}
    buf.write(b); off+=len(b)
open(f"{OUT_DIR}/cnn_scratch.bin","wb").write(buf.getvalue())
json.dump(man,open(f"{OUT_DIR}/cnn_scratch.json","w"),indent=2)
np.save(f"{OUT_DIR}/_wcache.npy",W,allow_pickle=True)
print(f"\nWROTE bin={off} bytes + manifest, params={len(man['params'])}")
