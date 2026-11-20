#!/usr/bin/env python3
"""
Deterministic 1920x1080 @ 30fps Playwright + OpenCV/PIL + FFmpeg demo recorder
for Faultline MCP (http://127.0.0.1:8080/).

Produces:
  artifacts/Faultline_MCP_Demo_Video.mp4 (H.264 yuv420p, 1920x1080, 30fps, 74.0s)
"""

from __future__ import annotations

import glob
import io
import math
import os
import shutil
import subprocess
import sys
import urllib.request
from dataclasses import dataclass
from pathlib import Path

import cv2
import numpy as np
from PIL import Image, ImageDraw, ImageFont
from playwright.sync_api import Page, sync_playwright

FPS = 30
WIDTH = 1920
HEIGHT = 1080
WEB_H = 1020
HUD_H = 60
DPR = 1.25
VIEWPORT_W = int(WIDTH / DPR)   # 1536
VIEWPORT_H = int(WEB_H / DPR)   # 816
TOTAL_DURATION_S = 74.0
TOTAL_FRAMES = int(TOTAL_DURATION_S * FPS)  # 2220 frames

BASE_URL = os.environ.get("FAULTLINE_URL", "http://127.0.0.1:8080")
REPO_ROOT = Path(__file__).resolve().parent.parent
OUTPUT_MP4 = REPO_ROOT / "artifacts" / "Faultline_MCP_Demo_Video.mp4"


def find_binary(name: str) -> str:
    found = shutil.which(name)
    if found:
        return found

    winget_glob = os.path.expanduser(
        f"~/AppData/Local/Microsoft/WinGet/Packages/Gyan.FFmpeg*/ffmpeg-*/bin/{name}.exe"
    )
    matches = sorted(glob.glob(winget_glob), reverse=True)
    if matches:
        return matches[0]

    oopz_path = os.path.expanduser(f"~/AppData/Local/oopz/{name}.exe")
    if os.path.isfile(oopz_path):
        return oopz_path

    raise FileNotFoundError(f"Required binary '{name}' not found in PATH or standard locations.")


def ease_in_out_cubic(t: float) -> float:
    t = max(0.0, min(1.0, t))
    if t < 0.5:
        return 4.0 * t * t * t
    return 1.0 - pow(-2.0 * t + 2.0, 3) / 2.0


def load_fonts() -> tuple[ImageFont.FreeTypeFont, ImageFont.FreeTypeFont, ImageFont.FreeTypeFont]:
    candidates_bold = [
        "C:/Windows/Fonts/segoeuib.ttf",
        "C:/Windows/Fonts/consolab.ttf",
        "C:/Windows/Fonts/arialbd.ttf",
    ]
    candidates_mono = [
        "C:/Windows/Fonts/consolab.ttf",
        "C:/Windows/Fonts/consola.ttf",
    ]
    candidates_reg = [
        "C:/Windows/Fonts/segoeui.ttf",
        "C:/Windows/Fonts/consola.ttf",
        "C:/Windows/Fonts/arial.ttf",
    ]

    def pick(paths: list[str], size: int) -> ImageFont.FreeTypeFont:
        for p in paths:
            if os.path.isfile(p):
                return ImageFont.truetype(p, size=size)
        return ImageFont.load_default()

    return pick(candidates_mono, 15), pick(candidates_bold, 17), pick(candidates_reg, 16)


FONT_MONO, FONT_BOLD, FONT_REG = load_fonts()


@dataclass
class HudState:
    beat_tag: str
    headline: str
    detail: str
    accent_rgb: tuple[int, int, int] = (245, 158, 11)  # #F59E0B amber


class VideoComposer:
    def __init__(self, ffmpeg_bin: str, out_path: Path) -> None:
        out_path.parent.mkdir(parents=True, exist_ok=True)
        cmd = [
            ffmpeg_bin,
            "-y",
            "-f", "rawvideo",
            "-vcodec", "rawvideo",
            "-pix_fmt", "bgr24",
            "-s", f"{WIDTH}x{HEIGHT}",
            "-r", str(FPS),
            "-i", "-",
            "-c:v", "libx264",
            "-pix_fmt", "yuv420p",
            "-preset", "slow",
            "-crf", "16",
            "-profile:v", "high",
            "-level:v", "4.2",
            "-movflags", "+faststart",
            str(out_path),
        ]
        self.proc = subprocess.Popen(
            cmd,
            stdin=subprocess.PIPE,
            stdout=subprocess.DEVNULL,
            stderr=subprocess.PIPE,
        )
        self.frame_idx = 0
        self.cursor_x = float(WIDTH) * 0.50
        self.cursor_y = float(WEB_H) * 0.45
        self.cursor_visible = True
        self.hud = HudState(
            beat_tag="BEAT 1/4",
            headline="POST-COMMIT TRUNCATION",
            detail="Seed #20261123 · Initializing Faultline MCP Oscilloscope",
        )
        self._hud_cache_key: tuple[str, str, str, tuple[int, int, int]] | None = None
        self._hud_base_bgr: np.ndarray | None = None

    def _render_hud_base(self, hud: HudState) -> np.ndarray:
        key = (hud.beat_tag, hud.headline, hud.detail, hud.accent_rgb)
        if self._hud_cache_key == key and self._hud_base_bgr is not None:
            return self._hud_base_bgr

        img = Image.new("RGB", (WIDTH, HUD_H), (15, 23, 42))  # #0F172A slate-900
        draw = ImageDraw.Draw(img)

        # Top hairline border
        draw.line([(0, 0), (WIDTH, 0)], fill=(51, 65, 85), width=2)

        # Beat pill
        pill_x0, pill_y0, pill_x1, pill_y1 = 28, 13, 134, 44
        draw.rounded_rectangle(
            [(pill_x0, pill_y0), (pill_x1, pill_y1)],
            radius=4,
            fill=hud.accent_rgb,
        )
        bbox = draw.textbbox((0, 0), hud.beat_tag, font=FONT_MONO)
        tw = bbox[2] - bbox[0]
        th = bbox[3] - bbox[1]
        draw.text(
            (pill_x0 + (pill_x1 - pill_x0 - tw) / 2, pill_y0 + (pill_y1 - pill_y0 - th) / 2 - 2),
            hud.beat_tag,
            font=FONT_MONO,
            fill=(15, 23, 42),
        )

        # Headline
        head_x = 152
        draw.text((head_x, 18), hud.headline, font=FONT_BOLD, fill=(241, 245, 249))
        hbox = draw.textbbox((head_x, 18), hud.headline, font=FONT_BOLD)

        # Separator + Detail
        sep_x = hbox[2] + 18
        draw.line([(sep_x, 18), (sep_x, 39)], fill=(71, 85, 105), width=2)
        draw.text((sep_x + 18, 18), hud.detail, font=FONT_REG, fill=(203, 213, 225))

        rgb = np.asarray(img, dtype=np.uint8)
        self._hud_base_bgr = cv2.cvtColor(rgb, cv2.COLOR_RGB2BGR)
        self._hud_cache_key = key
        return self._hud_base_bgr

    def _draw_cursor(self, frame: np.ndarray, x: float, y: float, click_progress: float | None) -> None:
        if not self.cursor_visible:
            return
        ix, iy = int(round(x)), int(round(y))

        if click_progress is not None and 0.0 <= click_progress <= 1.0:
            radius = int(10 + 26 * click_progress)
            alpha = 1.0 - click_progress
            overlay = frame.copy()
            cv2.circle(overlay, (ix, iy), radius, (11, 158, 245), 3, cv2.LINE_AA)  # BGR amber
            cv2.circle(overlay, (ix, iy), max(4, radius // 2), (129, 185, 16), 2, cv2.LINE_AA)
            cv2.addWeighted(overlay, 0.65 * alpha, frame, 1.0 - 0.65 * alpha, 0, frame)

        # Crisp vector arrow cursor
        pts = np.array([
            [ix, iy],
            [ix, iy + 22],
            [ix + 6, iy + 17],
            [ix + 11, iy + 26],
            [ix + 14, iy + 24],
            [ix + 9, iy + 15],
            [ix + 17, iy + 14],
        ], dtype=np.int32)
        shadow_pts = pts + np.array([[2, 2]], dtype=np.int32)
        cv2.fillPoly(frame, [shadow_pts], (20, 20, 20), lineType=cv2.LINE_AA)
        cv2.fillPoly(frame, [pts], (255, 255, 255), lineType=cv2.LINE_AA)
        cv2.polylines(frame, [pts], isClosed=True, color=(15, 23, 42), thickness=2, lineType=cv2.LINE_AA)

    def write_frame(self, web_bgr: np.ndarray, click_progress: float | None = None) -> None:
        if web_bgr.shape[0] != WEB_H or web_bgr.shape[1] != WIDTH:
            web_bgr = cv2.resize(web_bgr, (WIDTH, WEB_H), interpolation=cv2.INTER_AREA)

        canvas = np.empty((HEIGHT, WIDTH, 3), dtype=np.uint8)
        canvas[:WEB_H, :, :] = web_bgr
        self._draw_cursor(canvas[:WEB_H, :, :], self.cursor_x, self.cursor_y, click_progress)

        hud_bgr = self._render_hud_base(self.hud).copy()

        # Time readout + progress track at bottom of HUD
        elapsed_s = min(TOTAL_DURATION_S, self.frame_idx / FPS)
        time_str = f"{elapsed_s:04.1f}s / {TOTAL_DURATION_S:.0f}s"
        cv2.putText(
            hud_bgr,
            time_str,
            (WIDTH - 122, 34),
            cv2.FONT_HERSHEY_SIMPLEX,
            0.46,
            (226, 232, 240),
            1,
            cv2.LINE_AA,
        )

        prog_w = int(round((self.frame_idx / max(1, TOTAL_FRAMES - 1)) * WIDTH))
        cv2.rectangle(hud_bgr, (0, HUD_H - 4), (WIDTH, HUD_H), (30, 41, 59), -1)
        accent_bgr = (self.hud.accent_rgb[2], self.hud.accent_rgb[1], self.hud.accent_rgb[0])
        if prog_w > 0:
            cv2.rectangle(hud_bgr, (0, HUD_H - 4), (prog_w, HUD_H), accent_bgr, -1)

        canvas[WEB_H:, :, :] = hud_bgr

        assert self.proc.stdin is not None
        self.proc.stdin.write(canvas.tobytes())
        self.frame_idx += 1

    def hold(self, web_bgr: np.ndarray, seconds: float) -> None:
        n = max(1, int(round(seconds * FPS)))
        for _ in range(n):
            self.write_frame(web_bgr)

    def glide_to(self, web_bgr: np.ndarray, target_x: float, target_y: float, seconds: float) -> None:
        n = max(1, int(round(seconds * FPS)))
        sx, sy = self.cursor_x, self.cursor_y
        for i in range(n):
            t = ease_in_out_cubic((i + 1) / n)
            self.cursor_x = sx + (target_x - sx) * t
            self.cursor_y = sy + (target_y - sy) * t
            self.write_frame(web_bgr)

    def click_pulse(self, web_bgr: np.ndarray, seconds: float = 0.35) -> None:
        n = max(1, int(round(seconds * FPS)))
        for i in range(n):
            self.write_frame(web_bgr, click_progress=(i + 1) / n)

    def finalize(self) -> None:
        # Pad or trim nothing — emit remaining frames if any slight rounding delta (< 5 frames)
        if self.proc.stdin:
            self.proc.stdin.close()
        stderr_data = self.proc.stderr.read().decode("utf-8", errors="replace") if self.proc.stderr else ""
        rc = self.proc.wait()
        if rc != 0:
            raise RuntimeError(f"FFmpeg exited with code {rc}:\n{stderr_data}")


def capture_web_bgr(page: Page) -> np.ndarray:
    png_bytes = page.screenshot(type="png")
    arr = np.frombuffer(png_bytes, dtype=np.uint8)
    bgr = cv2.imdecode(arr, cv2.IMREAD_COLOR)
    if bgr is None:
        raise RuntimeError("Failed to decode Playwright PNG screenshot")
    return bgr


def element_center_px(page: Page, selector: str) -> tuple[float, float]:
    box = page.locator(selector).first.bounding_box()
    if not box:
        raise RuntimeError(f"Element not visible for selector: {selector}")
    cx = (box["x"] + box["width"] * 0.5) * DPR
    cy = (box["y"] + box["height"] * 0.5) * DPR
    return cx, cy


def smooth_scroll(page: Page, comp: VideoComposer, start_y: int, end_y: int, seconds: float) -> np.ndarray:
    n = max(1, int(round(seconds * FPS)))
    last_bgr = capture_web_bgr(page)
    for i in range(n):
        t = ease_in_out_cubic((i + 1) / n)
        y = round(start_y + (end_y - start_y) * t)
        page.evaluate(f"window.scrollTo(0, {y})")
        last_bgr = capture_web_bgr(page)
        comp.write_frame(last_bgr)
    return last_bgr


def click_and_settle(
    page: Page,
    comp: VideoComposer,
    selector: str,
    glide_s: float,
    wait_api: bool = False,
) -> np.ndarray:
    tx, ty = element_center_px(page, selector)
    current_bgr = capture_web_bgr(page)
    comp.glide_to(current_bgr, tx, ty, glide_s)

    if wait_api:
        with page.expect_response(lambda r: "/api/duel" in r.url and r.status == 200, timeout=15000):
            page.locator(selector).first.click()
    else:
        page.locator(selector).first.click()

    page.wait_for_timeout(120)
    updated_bgr = capture_web_bgr(page)
    comp.click_pulse(updated_bgr, seconds=0.35)
    return updated_bgr


def verify_server_ready(url: str) -> None:
    try:
        with urllib.request.urlopen(url, timeout=5) as resp:
            if resp.status != 200:
                raise RuntimeError(f"Unexpected HTTP status {resp.status} from {url}")
    except Exception as exc:
        raise RuntimeError(
            f"Faultline MCP server is not reachable at {url}. "
            "Start it with `docker start faultline-mcp` first."
        ) from exc


def main() -> int:
    verify_server_ready(BASE_URL)
    ffmpeg_bin = find_binary("ffmpeg")
    ffprobe_bin = find_binary("ffprobe")
    print(f"Using FFmpeg: {ffmpeg_bin}")
    print(f"Target output: {OUTPUT_MP4}")

    comp = VideoComposer(ffmpeg_bin, OUTPUT_MP4)

    with sync_playwright() as pw:
        browser = pw.chromium.launch(headless=True)
        context = browser.new_context(
            viewport={"width": VIEWPORT_W, "height": VIEWPORT_H},
            device_scale_factor=DPR,
        )
        page = context.new_page()
        page.goto(BASE_URL, wait_until="networkidle")
        page.evaluate("document.fonts ? document.fonts.ready : Promise.resolve()")
        page.wait_for_timeout(350)

        # Ensure crisp clean initial state at top
        page.evaluate("window.scrollTo(0, 0)")
        max_scroll = int(page.evaluate("Math.max(0, document.documentElement.scrollHeight - window.innerHeight)"))

        # ------------------------------------------------------------------
        # BEAT 1 (0.0s - 16.0s): CH-01 Post-Commit Truncated Frame
        # ------------------------------------------------------------------
        comp.hud = HudState(
            beat_tag="BEAT 1/4",
            headline="CH-01 POST-COMMIT TRUNCATED FRAME",
            detail="Seed #20261123 · Clicking INJECT & REPLAY to trigger byte-42 JSON-RPC wire cut",
            accent_rgb=(245, 158, 11),
        )
        frame = capture_web_bgr(page)
        comp.hold(frame, 1.4)

        frame = click_and_settle(page, comp, "#run-duel-btn", glide_s=0.9, wait_api=True)
        sealed_key = page.evaluate(
            """() => {
                const txt = (document.getElementById('diff-g-rows')?.textContent || '');
                const m = txt.match(/flt_[a-zA-Z0-9]+/);
                return m ? m[0] : 'flt_sealed';
            }"""
        )
        comp.hud = HudState(
            beat_tag="BEAT 1/4",
            headline="DOUBLE REFUND CAUGHT VS SEALED REPLAY",
            detail="CH1: TRUNC_42B -> 2X_LEAK (-298.00 EUR, 10/100)  |  CH2: SEALED -> SAFE_REPLAY (-149.00 EUR, 100/100)",
            accent_rgb=(245, 158, 11),
        )
        comp.hold(frame, 4.8)

        # Highlight CH2 SEALED & SAFE_REPLAY nodes
        frame = click_and_settle(page, comp, '[data-track="guarded"][data-seq="5"]', glide_s=0.75)
        comp.hold(frame, 1.8)

        frame = click_and_settle(page, comp, '[data-track="guarded"][data-seq="7"]', glide_s=0.65)
        comp.hold(frame, 1.8)

        # Select CH1 TRUNC_42B node right before scrolling into Beat 2
        frame = click_and_settle(page, comp, '[data-track="unguarded"][data-seq="4"]', glide_s=0.75)
        comp.hold(frame, 1.7)

        # ------------------------------------------------------------------
        # BEAT 2 (16.0s - 36.0s): Wire Frame Truncation & SQLite Ledger Diff
        # ------------------------------------------------------------------
        comp.hud = HudState(
            beat_tag="BEAT 2/4",
            headline="WIRE FRAME INSPECTOR & LEDGER DIFF",
            detail=f"42 B severed JSON-RPC payload after SQLite commit · RFND-0001 + RFND-0002 vs single {sealed_key} row",
            accent_rgb=(245, 158, 11),
        )
        frame = smooth_scroll(page, comp, 0, max_scroll, seconds=1.8)
        comp.hold(frame, 7.2)

        # Switch inspector to CH2 Guarded & inspect SAFE_IDEMPOTENT_REPLAY
        frame = click_and_settle(page, comp, '[data-track-filter="guarded"]', glide_s=0.8)
        comp.hud = HudState(
            beat_tag="BEAT 2/4",
            headline=f"CAUSAL IDEMPOTENCY SEAL ({sealed_key})",
            detail="CH2 Guarded replays sealed CallToolResult on retry · Zero duplicate SQLite rows committed",
            accent_rgb=(16, 185, 129),
        )
        comp.hold(frame, 2.8)

        frame = click_and_settle(page, comp, 'tr[data-row-seq="7"]', glide_s=0.65)
        comp.hold(frame, 3.3)

        # Reset track filter to CH1 Unguarded smoothly before scrolling back up
        frame = click_and_settle(page, comp, '[data-track-filter="unguarded"]', glide_s=0.6)
        frame = smooth_scroll(page, comp, max_scroll, 0, seconds=1.6)

        # ------------------------------------------------------------------
        # BEAT 3 (36.0s - 56.0s): CH-02 HTTP 429 Storm & CH-04 100x Schema Drift
        # ------------------------------------------------------------------
        comp.hud = HudState(
            beat_tag="BEAT 3/4",
            headline="CH-02 HTTP 429 STORM + POST-COMMIT 502",
            detail="HttpTransport.php:465-486 drops Retry-After -> +170.00 EUR double charge vs BACKOFF_OK (+85.00 EUR)",
            accent_rgb=(245, 158, 11),
        )
        frame = click_and_settle(page, comp, '[data-scenario="http_429_storm"]', glide_s=0.85, wait_api=True)
        comp.hold(frame, 4.1)

        frame = click_and_settle(page, comp, '[data-track="guarded"][data-seq="6"]', glide_s=0.7)
        comp.hold(frame, 3.6)

        # Switch to CH-04 100x Schema Drift
        comp.hud = HudState(
            beat_tag="BEAT 3/4",
            headline="CH-04 100x SCHEMA DRIFT PREVENTED",
            detail="Mid-session cents->EUR drift causes -14,900.00 EUR leak on CH1 · Blocked by SHA-256 schema pin on CH2",
            accent_rgb=(16, 185, 129),
        )
        frame = click_and_settle(page, comp, '[data-scenario="schema_drift"]', glide_s=0.85, wait_api=True)
        comp.hold(frame, 4.2)

        frame = click_and_settle(page, comp, '[data-track="guarded"][data-seq="5"]', glide_s=0.7)
        comp.hold(frame, 3.6)

        # ------------------------------------------------------------------
        # BEAT 4 (56.0s - 74.0s): CH-05 Cursor Poison & CH-06 Full Gauntlet
        # ------------------------------------------------------------------
        comp.hud = HudState(
            beat_tag="BEAT 4/4",
            headline="CH-05 PAGINATION CURSOR CYCLE BREAKER",
            detail="ClientToolset.php:46-64 spins into 15 poisoned tools (0/100) · Loop broken at 4 verified tools (100/100)",
            accent_rgb=(16, 185, 129),
        )
        frame = click_and_settle(page, comp, '[data-scenario="cursor_tamper"]', glide_s=0.85, wait_api=True)
        comp.hold(frame, 4.2)

        frame = click_and_settle(page, comp, '[data-track="guarded"][data-seq="4"]', glide_s=0.7)
        comp.hold(frame, 3.3)

        # Switch to CH-06 Full Gauntlet
        comp.hud = HudState(
            beat_tag="BEAT 4/4",
            headline="CH-06 FULL GAUNTLET · 100/100 INVARIANT_PRESERVED",
            detail="Compound 429 + Truncation + Jitter: -440.00 EUR leak on CH1 vs -220.00 EUR guarded on CH2",
            accent_rgb=(16, 185, 129),
        )
        frame = click_and_settle(page, comp, '[data-scenario="gauntlet_all"]', glide_s=0.85, wait_api=True)
        # Park cursor cleanly in margin during final hold
        comp.glide_to(frame, WIDTH - 48.0, WEB_H - 48.0, seconds=0.6)

        remaining_frames = max(1, TOTAL_FRAMES - comp.frame_idx)
        for _ in range(remaining_frames):
            comp.write_frame(frame)

        browser.close()

    comp.finalize()

    probe_out = subprocess.check_output(
        [
            ffprobe_bin,
            "-v", "error",
            "-show_entries", "format=duration,size:stream=width,height,r_frame_rate,pix_fmt,codec_name",
            "-of", "default=noprint_wrappers=1",
            str(OUTPUT_MP4),
        ],
        text=True,
    )
    print("FFprobe summary:\n" + probe_out.strip())
    return 0


if __name__ == "__main__":
    sys.exit(main())
