#!/usr/bin/env python3
# -*- coding: utf-8 -*-
"""
生成背景音乐（BGM）——约 2 分钟偏休闲的纯音乐。

用法：
    python3 scripts/generate_bgm.py

输出：frontend/public/audio/bgm/main.wav

思路：低音铺底( pad ) + 轻拨音色( pluck )做琶音 + 五声音阶旋律，
     三层叠加营造舒缓、留白的氛围。所有音符与和弦都在下方数据表里，
     想换调式/加快速度改常数即可。
"""

import os
import math
import random
import struct
import wave

SR = 22050                 # 采样率（纯音乐用 22k 即可，文件更小）
DURATION = 120.0           # 总时长（秒）
BAR_LEN = 8.0              # 每个和弦保持时长（秒）
SEED = 7                   # 固定随机种子，保证每次生成结果一致

OUT_DIR = os.path.join(
    os.path.dirname(os.path.abspath(__file__)),
    '..', 'frontend', 'public', 'audio', 'bgm'
)

# 五声音阶（C 宫调式，符合仙侠/休闲气质）
PENTA = [261.63, 293.66, 329.63, 392.00, 440.00, 523.25, 587.33, 659.25, 783.99, 880.00]

# 和弦进行：I - vi - IV - V，循环
PROG = ['C', 'Am', 'F', 'G']

# 每个和弦：pad 用的和弦音（低到高）+ 琶音用的音
CHORDS = {
    'C':  {'bass': 130.81, 'pad': [130.81, 261.63, 329.63, 392.00],
           'pluck': [261.63, 329.63, 392.00, 523.25]},
    'Am': {'bass': 110.00, 'pad': [110.00, 220.00, 261.63, 329.63],
           'pluck': [220.00, 261.63, 329.63, 440.00]},
    'F':  {'bass': 87.31,  'pad': [87.31, 174.61, 220.00, 261.63],
           'pluck': [174.61, 220.00, 261.63, 349.23]},
    'G':  {'bass': 98.00,  'pad': [98.00, 196.00, 246.94, 293.66],
           'pluck': [196.00, 246.94, 293.66, 392.00]},
}


# ==================== 合成基元 ====================

def render_pad(freq, dur, vol, attack=1.5, release=1.5,
               harm=((1, 1.0), (2, 0.22), (3, 0.08))):
    """铺底长音：慢起慢落，带少量泛音，声音柔和。"""
    n = int(SR * dur)
    out = []
    for i in range(n):
        t = i / SR
        env = 1.0
        if i < attack * SR:
            env = i / (attack * SR)
        remain = (n - 1 - i) / SR
        if remain < release:
            env *= remain / release
        s = sum(a * math.sin(2 * math.pi * freq * m * t) for m, a in harm)
        out.append(s * vol * env)
    return out


def render_pluck(freq, dur, vol, decay=0.45,
                 harm=((1, 1.0), (2, 0.28), (3, 0.07))):
    """轻拨音色：短促、明亮、快速衰减，类似拇指琴/八音盒。"""
    n = int(SR * dur)
    attack_n = max(1, int(SR * 0.002))
    out = []
    for i in range(n):
        t = i / SR
        if i < attack_n:
            env = i / attack_n
        else:
            env = math.exp(-(i - attack_n) / (SR * decay))
        s = sum(a * math.sin(2 * math.pi * freq * m * t) for m, a in harm)
        out.append(s * vol * env)
    return out


def add(buf, seg, start):
    """把一段采样叠加到缓冲区指定时间点。"""
    off = int(start * SR)
    for i, v in enumerate(seg):
        idx = off + i
        if idx < len(buf):
            buf[idx] += v


def add_pluck(buf, freq, t, vol, echo=True):
    """放一个轻拨音，可附带两次回声营造空间感。"""
    add(buf, render_pluck(freq, 2.0, vol), t)
    if echo:
        add(buf, render_pluck(freq, 2.0, vol * 0.35), t + 0.45)
        add(buf, render_pluck(freq, 2.0, vol * 0.18), t + 0.90)


# ==================== 写文件 ====================

def normalize(buf):
    peak = max((abs(v) for v in buf), default=0.0) or 1.0
    scale = min(1.0, 0.90 / peak)
    return [v * scale for v in buf]


def write_wav(path, buf):
    os.makedirs(os.path.dirname(path), exist_ok=True)
    with wave.open(path, 'wb') as w:
        w.setnchannels(1)
        w.setsampwidth(2)
        w.setframerate(SR)
        frames = bytearray()
        for s in buf:
            v = max(-1.0, min(1.0, s))
            frames += struct.pack('<h', int(v * 32767))
        w.writeframes(bytes(frames))


# ==================== 主流程 ====================

def main():
    rng = random.Random(SEED)
    n_bars = int(math.ceil(DURATION / BAR_LEN))
    buf = [0.0] * int(SR * DURATION)

    for b in range(n_bars):
        t0 = b * BAR_LEN
        chord = CHORDS[PROG[b % len(PROG)]]

        # 1) 铺底：和弦音 + 低音做持续垫
        for f in chord['pad']:
            add(buf, render_pad(f, BAR_LEN + 1.5, 0.16), t0 - 0.75)
        add(buf, render_pad(chord['bass'], BAR_LEN + 1.5, 0.20), t0 - 0.75)

        # 2) 琶音：按固定节奏轻拨和弦音（来回拨动）
        pluck_times = [0.0, 1.2, 2.4, 3.6, 5.0, 6.2]
        pat = [0, 1, 2, 3, 2, 1]
        for k, dt in enumerate(pluck_times):
            if t0 + dt >= DURATION:
                break
            add_pluck(buf, chord['pluck'][pat[k]], t0 + dt, 0.15)

    # 3) 旋律：五声音阶做柔和漫步，每 2 秒一个音
    idx = 4
    t = 0.5
    while t < DURATION - 1.0:
        step = rng.choice([-2, -1, -1, 1, 1, 2])
        idx = max(0, min(len(PENTA) - 1, idx + step))
        # 低一点更放松，偶尔跳到高音
        note = PENTA[idx]
        if rng.random() < 0.25:
            note /= 2.0
        add_pluck(buf, note, t, 0.13, echo=True)
        t += 2.0

    buf = normalize(buf)
    path = os.path.join(OUT_DIR, 'main.wav')
    write_wav(path, buf)
    print(f'生成 main.wav  ({len(buf)/SR:.1f}s, {os.path.getsize(path)/1024:.0f} KB)')


if __name__ == '__main__':
    main()