#!/usr/bin/env python3
# -*- coding: utf-8 -*-
"""
生成游戏动作音效（SFX）占位音频文件。

用法：
    python3 scripts/generate_sfx.py

输出：frontend/public/audio/sfx/*.wav

如何新增一个动作音效：
    1. 在下方 SOUNDS 里加一项（key 对应 config/audio.json 里 sfx 的 id）
    2. 运行本脚本即可生成对应的 .wav，无需改其它代码
    3. 如需 mp3，自行用 afconvert / ffmpeg 转换，本脚本只产出 wav
"""

import os
import math
import struct
import wave

SR = 44100             # 采样率 44.1kHz
OUT_DIR = os.path.join(
    os.path.dirname(os.path.abspath(__file__)),
    '..', 'frontend', 'public', 'audio', 'sfx'
)

# 每个音效 = 一串音符；音符字段：
#   freq  基频(Hz)
#   at    起始时间(秒)
#   dur   持续时长(秒)
#   vol   音量(0~1，混音时勿过大)
#   decay 衰减系数(秒，越大残响越长)
#   harm  谐波列表 [(倍数, 幅度)]，缺省为纯正弦
SOUNDS = {
    # 通用按钮点击：极短的一声轻响
    'click': [
        {'freq': 1000, 'at': 0.0, 'dur': 0.045, 'vol': 0.40, 'decay': 0.010},
    ],

    # 冥想开始：轻柔的上行双音
    'meditate_start': [
        {'freq': 523.25, 'at': 0.00, 'dur': 0.16, 'vol': 0.30, 'decay': 0.10,
         'harm': [(1, 1.0), (2, 0.30)]},
        {'freq': 659.25, 'at': 0.13, 'dur': 0.26, 'vol': 0.28, 'decay': 0.18,
         'harm': [(1, 1.0), (2, 0.30)]},
    ],

    # 冥想结束：温和的钟鸣（大三度和声）
    'meditate_done': [
        {'freq': 523.25, 'at': 0.0, 'dur': 0.70, 'vol': 0.30, 'decay': 0.35,
         'harm': [(1, 1.0), (2, 0.35), (3, 0.12)]},
        {'freq': 659.25, 'at': 0.0, 'dur': 0.70, 'vol': 0.22, 'decay': 0.40,
         'harm': [(1, 1.0), (2, 0.25)]},
    ],

    # 闭关开始：低沉悠长的钟声
    'retreat_start': [
        {'freq': 196.00, 'at': 0.0, 'dur': 0.90, 'vol': 0.50, 'decay': 0.50,
         'harm': [(1, 1.0), (2, 0.30), (3, 0.10), (4, 0.05)]},
    ],

    # 闭关结束：上行琶音 + 尾部长音
    'retreat_done': [
        {'freq': 329.63, 'at': 0.00, 'dur': 0.40, 'vol': 0.30, 'decay': 0.20,
         'harm': [(1, 1.0), (2, 0.30)]},
        {'freq': 392.00, 'at': 0.12, 'dur': 0.40, 'vol': 0.30, 'decay': 0.22,
         'harm': [(1, 1.0), (2, 0.30)]},
        {'freq': 523.25, 'at': 0.24, 'dur': 0.50, 'vol': 0.32, 'decay': 0.30,
         'harm': [(1, 1.0), (2, 0.35), (3, 0.12)]},
        {'freq': 659.25, 'at': 0.36, 'dur': 0.60, 'vol': 0.28, 'decay': 0.40,
         'harm': [(1, 1.0), (2, 0.30)]},
    ],

    # 突破：上升的连续音阶 + 明亮收尾
    'breakthrough': [
        {'freq': 523.25, 'at': 0.00, 'dur': 0.14, 'vol': 0.32, 'decay': 0.12,
         'harm': [(1, 1.0), (2, 0.30)]},
        {'freq': 587.33, 'at': 0.08, 'dur': 0.14, 'vol': 0.32, 'decay': 0.12,
         'harm': [(1, 1.0), (2, 0.30)]},
        {'freq': 659.25, 'at': 0.16, 'dur': 0.14, 'vol': 0.34, 'decay': 0.12,
         'harm': [(1, 1.0), (2, 0.30)]},
        {'freq': 783.99, 'at': 0.24, 'dur': 0.14, 'vol': 0.34, 'decay': 0.12,
         'harm': [(1, 1.0), (2, 0.30)]},
        {'freq': 880.00, 'at': 0.32, 'dur': 0.16, 'vol': 0.36, 'decay': 0.14,
         'harm': [(1, 1.0), (2, 0.30)]},
        {'freq': 1046.50, 'at': 0.42, 'dur': 0.55, 'vol': 0.38, 'decay': 0.45,
         'harm': [(1, 1.0), (2, 0.40), (3, 0.15)]},
    ],
}


def render_note(freq, dur, vol, decay, harm):
    """合成单个音符：带 4ms 起音 + 指数衰减，避免爆音。"""
    n = int(SR * dur)
    attack_n = int(SR * 0.004)
    out = []
    for i in range(n):
        t = i / SR
        if attack_n > 0 and i < attack_n:
            env = i / attack_n
        else:
            env = math.exp(-(i - attack_n) / (SR * decay))
        s = 0.0
        for m, a in harm:
            s += a * math.sin(2 * math.pi * freq * m * t)
        out.append(s * vol * env)
    return out


def build(notes):
    """把一组音符按起始时间混到同一个缓冲区。"""
    total = max((n['at'] + n['dur'] for n in notes), default=0.0)
    n_samples = int(SR * total) + 1
    buf = [0.0] * n_samples
    for n in notes:
        harm = n.get('harm', [(1, 1.0)])
        seg = render_note(n['freq'], n['dur'], n['vol'], n['decay'], harm)
        off = int(n['at'] * SR)
        for i, v in enumerate(seg):
            idx = off + i
            if idx < n_samples:
                buf[idx] += v
    return buf


def normalize(buf):
    """整体压到安全范围，避免削波。"""
    peak = max((abs(v) for v in buf), default=0.0) or 1.0
    scale = min(1.0, 0.92 / peak)
    return [v * scale for v in buf]


def write_wav(path, buf):
    with wave.open(path, 'wb') as w:
        w.setnchannels(1)
        w.setsampwidth(2)
        w.setframerate(SR)
        frames = bytearray()
        for s in buf:
            v = max(-1.0, min(1.0, s))
            frames += struct.pack('<h', int(v * 32767))
        w.writeframes(bytes(frames))


def main():
    os.makedirs(OUT_DIR, exist_ok=True)
    for sfx_id, notes in SOUNDS.items():
        buf = normalize(build(notes))
        path = os.path.join(OUT_DIR, f'{sfx_id}.wav')
        write_wav(path, buf)
        print(f'生成 {sfx_id}.wav  ({len(notes)} 音符, {len(buf)/SR:.2f}s)')


if __name__ == '__main__':
    main()