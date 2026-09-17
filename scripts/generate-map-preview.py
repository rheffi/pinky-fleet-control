#!/usr/bin/env python3

"""Convert the authoritative binary PGM occupancy map to a browser PNG."""

from pathlib import Path
import binascii
import struct
import zlib


ROOT = Path(__file__).resolve().parents[1]
SOURCE = ROOT / "map" / "cbs_map.pgm"
TARGET = ROOT / "public" / "maps" / "cbs_map.png"


def read_pgm(path: Path) -> tuple[int, int, bytes]:
    data = path.read_bytes()
    position = 0

    def token() -> bytes:
        nonlocal position
        while True:
            while position < len(data) and data[position] in b" \t\r\n":
                position += 1
            if position < len(data) and data[position] == ord("#"):
                while position < len(data) and data[position] not in b"\r\n":
                    position += 1
                continue
            break
        start = position
        while position < len(data) and data[position] not in b" \t\r\n":
            position += 1
        return data[start:position]

    magic = token()
    width = int(token())
    height = int(token())
    maximum = int(token())
    while position < len(data) and data[position] in b" \t\r\n":
        position += 1
    pixels = data[position:]

    if magic != b"P5" or maximum != 255 or len(pixels) != width * height:
        raise ValueError("Expected an 8-bit binary PGM occupancy map")
    return width, height, pixels


def chunk(kind: bytes, payload: bytes) -> bytes:
    body = kind + payload
    checksum = binascii.crc32(body) & 0xFFFFFFFF
    return struct.pack(">I", len(payload)) + body + struct.pack(">I", checksum)


def write_png(path: Path, width: int, height: int, pixels: bytes) -> None:
    rows = b"".join(b"\x00" + pixels[y * width : (y + 1) * width] for y in range(height))
    header = struct.pack(">IIBBBBB", width, height, 8, 0, 0, 0, 0)
    png = b"\x89PNG\r\n\x1a\n" + chunk(b"IHDR", header)
    png += chunk(b"IDAT", zlib.compress(rows, 9)) + chunk(b"IEND", b"")
    path.parent.mkdir(parents=True, exist_ok=True)
    path.write_bytes(png)


width, height, pixels = read_pgm(SOURCE)
write_png(TARGET, width, height, pixels)
print(f"Created {TARGET.relative_to(ROOT)} ({width}x{height})")
