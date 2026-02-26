#!/usr/bin/env python3
"""
═══════════════════════════════════════════════════════════════════════════════
🎤 ONE VOICE ID - Reconhecimento de Voz Local
═══════════════════════════════════════════════════════════════════════════════

Identifica QUEM está falando pela voz.
Cada pessoa tem seu perfil único.

Requisitos:
    pip3 install numpy scipy librosa scikit-learn

Uso:
    python3 voice_id.py cadastrar audio.wav "Nome"
    python3 voice_id.py identificar audio.wav
    python3 voice_id.py listar
    python3 voice_id.py status

═══════════════════════════════════════════════════════════════════════════════
"""

import os
import sys
import json
import hashlib
import pickle
from pathlib import Path
from datetime import datetime

# Tenta importar bibliotecas
try:
    import numpy as np
    NUMPY_OK = True
except ImportError:
    NUMPY_OK = False

try:
    import librosa
    LIBROSA_OK = True
except ImportError:
    LIBROSA_OK = False

try:
    from sklearn.mixture import GaussianMixture
    from sklearn.preprocessing import StandardScaler
    SKLEARN_OK = True
except ImportError:
    SKLEARN_OK = False


# ═══════════════════════════════════════════════════════════════════════════════
# CONFIGURAÇÃO
# ═══════════════════════════════════════════════════════════════════════════════

DATA_DIR = Path("/var/www/html/mercado/voice_data")
PROFILES_FILE = DATA_DIR / "profiles.json"
MODELS_DIR = DATA_DIR / "models"

# Parâmetros de extração de features
N_MFCC = 20
N_MELS = 128
HOP_LENGTH = 512
N_FFT = 2048


# ═══════════════════════════════════════════════════════════════════════════════
# CLASSE PRINCIPAL
# ═══════════════════════════════════════════════════════════════════════════════

class VoiceID:
    """Sistema de identificação de voz"""
    
    def __init__(self):
        # Cria diretórios se não existem
        DATA_DIR.mkdir(parents=True, exist_ok=True)
        MODELS_DIR.mkdir(parents=True, exist_ok=True)
        
        self.profiles = self._load_profiles()
        self.scaler = StandardScaler() if SKLEARN_OK else None
    
    def _load_profiles(self):
        """Carrega perfis do arquivo JSON"""
        if PROFILES_FILE.exists():
            try:
                with open(PROFILES_FILE, 'r', encoding='utf-8') as f:
                    return json.load(f)
            except:
                return {}
        return {}
    
    def _save_profiles(self):
        """Salva perfis no arquivo JSON"""
        with open(PROFILES_FILE, 'w', encoding='utf-8') as f:
            json.dump(self.profiles, f, indent=2, ensure_ascii=False)
    
    def extract_features(self, audio_file):
        """Extrai features de áudio para identificação"""
        if not LIBROSA_OK:
            return None
        
        try:
            # Carrega áudio
            y, sr = librosa.load(audio_file, sr=16000, mono=True)
            
            # Remove silêncio
            y, _ = librosa.effects.trim(y, top_db=20)
            
            if len(y) < sr * 0.5:  # Menos de 0.5 segundos
                return None
            
            # Extrai MFCC (Mel-frequency cepstral coefficients)
            mfcc = librosa.feature.mfcc(y=y, sr=sr, n_mfcc=N_MFCC, hop_length=HOP_LENGTH, n_fft=N_FFT)
            
            # Extrai delta e delta-delta
            mfcc_delta = librosa.feature.delta(mfcc)
            mfcc_delta2 = librosa.feature.delta(mfcc, order=2)
            
            # Extrai características espectrais adicionais
            spectral_centroid = librosa.feature.spectral_centroid(y=y, sr=sr, hop_length=HOP_LENGTH)
            spectral_bandwidth = librosa.feature.spectral_bandwidth(y=y, sr=sr, hop_length=HOP_LENGTH)
            spectral_rolloff = librosa.feature.spectral_rolloff(y=y, sr=sr, hop_length=HOP_LENGTH)
            
            # Combina todas as features
            features = np.vstack([
                mfcc,
                mfcc_delta,
                mfcc_delta2,
                spectral_centroid,
                spectral_bandwidth,
                spectral_rolloff
            ])
            
            # Transpõe para ter (n_frames, n_features)
            features = features.T
            
            return features
            
        except Exception as e:
            print(f"Erro ao extrair features: {e}", file=sys.stderr)
            return None
    
    def train_model(self, features, n_components=16):
        """Treina modelo GMM para um perfil de voz"""
        if not SKLEARN_OK or features is None:
            return None
        
        try:
            # Normaliza features
            features_norm = self.scaler.fit_transform(features)
            
            # Treina GMM
            gmm = GaussianMixture(
                n_components=min(n_components, len(features) // 2),
                covariance_type='diag',
                max_iter=200,
                random_state=42
            )
            gmm.fit(features_norm)
            
            return {
                'gmm': gmm,
                'scaler_mean': self.scaler.mean_.tolist(),
                'scaler_scale': self.scaler.scale_.tolist()
            }
            
        except Exception as e:
            print(f"Erro ao treinar modelo: {e}", file=sys.stderr)
            return None
    
    def cadastrar(self, audio_file, nome, customer_id=0):
        """Cadastra nova voz"""
        if not os.path.exists(audio_file):
            return {"success": False, "error": "Arquivo não encontrado"}
        
        # Extrai features
        features = self.extract_features(audio_file)
        if features is None or len(features) < 10:
            return {"success": False, "error": "Áudio muito curto ou inválido"}
        
        # Treina modelo
        model_data = self.train_model(features)
        if model_data is None:
            return {"success": False, "error": "Falha ao treinar modelo"}
        
        # Gera ID único
        voice_id = hashlib.md5(f"{nome}_{customer_id}_{datetime.now().isoformat()}".encode()).hexdigest()[:12]
        
        # Salva modelo
        model_file = MODELS_DIR / f"{voice_id}.pkl"
        with open(model_file, 'wb') as f:
            pickle.dump(model_data, f)
        
        # Atualiza perfil
        self.profiles[voice_id] = {
            "nome": nome,
            "customer_id": customer_id,
            "created_at": datetime.now().isoformat(),
            "model_file": str(model_file),
            "n_samples": 1
        }
        self._save_profiles()
        
        return {
            "success": True,
            "voice_id": voice_id,
            "nome": nome,
            "message": f"Voz de {nome} cadastrada com sucesso!"
        }
    
    def identificar(self, audio_file, threshold=0.6):
        """Identifica quem está falando"""
        if not os.path.exists(audio_file):
            return {"identified": False, "error": "Arquivo não encontrado"}
        
        if not self.profiles:
            return {"identified": False, "is_new": True, "message": "Nenhum perfil cadastrado"}
        
        # Extrai features
        features = self.extract_features(audio_file)
        if features is None or len(features) < 10:
            return {"identified": False, "error": "Áudio muito curto ou inválido"}
        
        # Compara com todos os perfis
        best_score = float('-inf')
        best_match = None
        scores = {}
        
        for voice_id, profile in self.profiles.items():
            model_file = Path(profile.get("model_file", ""))
            if not model_file.exists():
                continue
            
            try:
                with open(model_file, 'rb') as f:
                    model_data = pickle.load(f)
                
                # Reconstrói scaler
                scaler = StandardScaler()
                scaler.mean_ = np.array(model_data['scaler_mean'])
                scaler.scale_ = np.array(model_data['scaler_scale'])
                
                # Normaliza features
                features_norm = scaler.transform(features)
                
                # Calcula score
                gmm = model_data['gmm']
                score = gmm.score(features_norm)
                scores[voice_id] = score
                
                if score > best_score:
                    best_score = score
                    best_match = voice_id
                    
            except Exception as e:
                continue
        
        # Verifica se o melhor match é confiável
        if best_match and best_score > threshold:
            profile = self.profiles[best_match]
            
            # Calcula confiança baseada na diferença entre scores
            if len(scores) > 1:
                sorted_scores = sorted(scores.values(), reverse=True)
                confidence = min(1.0, (sorted_scores[0] - sorted_scores[1]) / 10 + 0.5)
            else:
                confidence = 0.8
            
            return {
                "identified": True,
                "voice_id": best_match,
                "nome": profile["nome"],
                "customer_id": profile.get("customer_id", 0),
                "confidence": round(confidence, 2),
                "score": round(best_score, 2)
            }
        
        return {
            "identified": False,
            "is_new": True,
            "best_score": round(best_score, 2) if best_score > float('-inf') else None,
            "message": "Voz não reconhecida"
        }
    
    def listar(self):
        """Lista todos os perfis cadastrados"""
        perfis = []
        for voice_id, profile in self.profiles.items():
            perfis.append({
                "voice_id": voice_id,
                "nome": profile["nome"],
                "customer_id": profile.get("customer_id", 0),
                "created_at": profile.get("created_at", ""),
                "n_samples": profile.get("n_samples", 1)
            })
        
        return {
            "success": True,
            "total": len(perfis),
            "perfis": perfis
        }
    
    def remover(self, voice_id):
        """Remove um perfil de voz"""
        if voice_id not in self.profiles:
            return {"success": False, "error": "Perfil não encontrado"}
        
        # Remove modelo
        profile = self.profiles[voice_id]
        model_file = Path(profile.get("model_file", ""))
        if model_file.exists():
            model_file.unlink()
        
        # Remove perfil
        del self.profiles[voice_id]
        self._save_profiles()
        
        return {"success": True, "message": "Perfil removido"}
    
    def status(self):
        """Retorna status do sistema"""
        return {
            "success": True,
            "status": "online",
            "version": "1.0",
            "dependencies": {
                "numpy": NUMPY_OK,
                "librosa": LIBROSA_OK,
                "sklearn": SKLEARN_OK
            },
            "total_perfis": len(self.profiles),
            "data_dir": str(DATA_DIR)
        }


# ═══════════════════════════════════════════════════════════════════════════════
# CLI
# ═══════════════════════════════════════════════════════════════════════════════

def main():
    if len(sys.argv) < 2:
        print(json.dumps({"error": "Uso: voice_id.py <comando> [args]"}))
        sys.exit(1)
    
    comando = sys.argv[1].lower()
    voice_id = VoiceID()
    
    if comando == "cadastrar":
        if len(sys.argv) < 4:
            print(json.dumps({"error": "Uso: voice_id.py cadastrar <audio.wav> <nome> [customer_id]"}))
            sys.exit(1)
        
        audio_file = sys.argv[2]
        nome = sys.argv[3]
        customer_id = int(sys.argv[4]) if len(sys.argv) > 4 else 0
        
        result = voice_id.cadastrar(audio_file, nome, customer_id)
        print(json.dumps(result))
    
    elif comando == "identificar":
        if len(sys.argv) < 3:
            print(json.dumps({"error": "Uso: voice_id.py identificar <audio.wav>"}))
            sys.exit(1)
        
        audio_file = sys.argv[2]
        result = voice_id.identificar(audio_file)
        print(json.dumps(result))
    
    elif comando == "listar":
        result = voice_id.listar()
        print(json.dumps(result))
    
    elif comando == "remover":
        if len(sys.argv) < 3:
            print(json.dumps({"error": "Uso: voice_id.py remover <voice_id>"}))
            sys.exit(1)
        
        vid = sys.argv[2]
        result = voice_id.remover(vid)
        print(json.dumps(result))
    
    elif comando == "status":
        result = voice_id.status()
        print(json.dumps(result))
    
    else:
        print(json.dumps({"error": f"Comando desconhecido: {comando}"}))
        sys.exit(1)


if __name__ == "__main__":
    main()
