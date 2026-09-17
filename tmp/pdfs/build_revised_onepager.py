from pathlib import Path

import fitz


ROOT = Path(__file__).resolve().parents[2]
OUTPUT = ROOT / "output" / "pdf" / "pinky-fleet-control-interim-onepager-revised.pdf"
MAP_IMAGE = ROOT / "public" / "maps" / "cbs_map.png"

FONT_REGULAR = Path(r"C:\Windows\Fonts\malgun.ttf")
FONT_BOLD = Path(r"C:\Windows\Fonts\malgunbd.ttf")

PAGE_W, PAGE_H = fitz.paper_size("a4-l")

NAVY = (19 / 255, 44 / 255, 60 / 255)
NAVY_2 = (28 / 255, 63 / 255, 82 / 255)
INK = (28 / 255, 49 / 255, 64 / 255)
MUTED = (95 / 255, 119 / 255, 134 / 255)
LINE = (210 / 255, 222 / 255, 228 / 255)
BG = (244 / 255, 247 / 255, 249 / 255)
WHITE = (1, 1, 1)
CYAN = (27 / 255, 183 / 255, 199 / 255)
CYAN_SOFT = (232 / 255, 248 / 255, 250 / 255)
BLUE = (65 / 255, 116 / 255, 1)
BLUE_SOFT = (236 / 255, 240 / 255, 1)
ORANGE = (250 / 255, 153 / 255, 73 / 255)
ORANGE_SOFT = (1, 244 / 255, 234 / 255)
GREEN = (39 / 255, 168 / 255, 105 / 255)
GREEN_SOFT = (233 / 255, 248 / 255, 240 / 255)
PURPLE = (190 / 255, 58 / 255, 216 / 255)


def box(page, x, y, w, h, fill=WHITE, stroke=LINE, width=0.8):
    page.draw_rect(fitz.Rect(x, y, x + w, y + h), color=stroke, fill=fill, width=width)


def text(page, x, y, w, h, value, size=10, color=INK, bold=False, align=0, lineheight=1.25):
    font = "MalgunBold" if bold else "Malgun"
    return page.insert_textbox(
        fitz.Rect(x, y, x + w, y + h),
        value,
        fontname=font,
        fontsize=size,
        color=color,
        align=align,
        lineheight=lineheight,
    )


def section_title(page, x, y, title, width=220):
    page.draw_rect(fitz.Rect(x, y + 1, x + 4, y + 16), color=CYAN, fill=CYAN)
    text(page, x + 11, y - 2, width, 22, title, size=12.5, bold=True)


def pill(page, x, y, w, label, fill=CYAN_SOFT, color=INK, size=7.5):
    page.draw_rect(fitz.Rect(x, y, x + w, y + 21), color=fill, fill=fill)
    text(page, x, y + 3, w, 14, label, size=size, color=color, bold=True, align=1)


def arrow(page, x1, y1, x2, y2, color=CYAN, width=1.7):
    page.draw_line(fitz.Point(x1, y1), fitz.Point(x2, y2), color=color, width=width)
    direction = 1 if x2 > x1 else -1
    head = 4.5
    page.draw_polyline(
        [
            fitz.Point(x2 - direction * head, y2 - 3),
            fitz.Point(x2, y2),
            fitz.Point(x2 - direction * head, y2 + 3),
        ],
        color=color,
        width=width,
    )


def number_step(page, x, y, n, title, detail, accent):
    box(page, x, y, 177, 47, fill=(248 / 255, 250 / 255, 251 / 255), stroke=(248 / 255, 250 / 255, 251 / 255))
    page.draw_circle(fitz.Point(x + 23, y + 23.5), 14, color=accent, fill=accent)
    text(page, x + 11, y + 15, 24, 14, f"{n:02d}", size=7.7, color=WHITE, bold=True, align=1)
    text(page, x + 45, y + 9, 122, 16, title, size=8.7, bold=True)
    text(page, x + 45, y + 27, 126, 14, detail, size=6.8, color=MUTED)


def build():
    OUTPUT.parent.mkdir(parents=True, exist_ok=True)
    doc = fitz.open()
    page = doc.new_page(width=PAGE_W, height=PAGE_H)
    page.insert_font(fontname="Malgun", fontfile=str(FONT_REGULAR))
    page.insert_font(fontname="MalgunBold", fontfile=str(FONT_BOLD))

    page.draw_rect(page.rect, color=BG, fill=BG)

    # Header
    page.draw_rect(fitz.Rect(0, 0, PAGE_W, 78), color=NAVY, fill=NAVY)
    page.draw_circle(fitz.Point(43, 39), 16, color=CYAN, fill=CYAN)
    text(page, 32, 25, 22, 28, "P", size=17, color=WHITE, bold=True, align=1)
    page.insert_text(
        fitz.Point(70, 36),
        "PINKY FLEET CONTROL",
        fontname="MalgunBold",
        fontsize=21,
        color=WHITE,
    )
    text(
        page,
        70,
        46,
        500,
        18,
        "로보틱스 강의 미니프로젝트 · PinkyPro 3대 통신 기반 관제 설계",
        size=9.2,
        color=(205 / 255, 220 / 255, 228 / 255),
    )
    box(page, 687, 23, 121, 31, fill=NAVY_2, stroke=(55 / 255, 91 / 255, 111 / 255))
    text(page, 687, 32, 121, 15, "중간 발표 · 추진안 · 2026.09", size=7.4, color=WHITE, bold=True, align=1)

    # Goal card
    box(page, 34, 95, 236, 155)
    section_title(page, 51, 110, "프로젝트 목표")
    text(
        page,
        51,
        144,
        202,
        55,
        "로봇-서버-관제 화면 사이의 통신을 중심으로,\nPinkyPro 3대의 위치·명령·상태를 한 화면에서\n관리하는 관제 구조를 구현하려 했다.",
        size=8.3,
        lineheight=1.45,
    )
    pill(page, 51, 205, 58, "PinkyPro × 3")
    pill(page, 115, 205, 63, "ROS 2 · Nav2")
    pill(page, 184, 205, 69, "내부망 운용")
    text(page, 51, 233, 202, 10, "발표 초점: 실제 로봇 통신 구조와 상태 전달", size=6.8, color=CYAN, bold=True)

    # Communication architecture card
    box(page, 282, 95, 526, 155)
    section_title(page, 299, 110, "로봇 통신 설계", width=250)
    text(page, 570, 112, 220, 14, "위쪽: 로봇→관제 / 아래쪽: 관제→로봇", size=6.4, color=MUTED, align=2)

    node_y, node_h, node_w = 155, 45, 100
    node_xs = [302, 433, 564, 695]
    node_specs = [
        ("로봇 ROS / Nav2", "실제 PinkyPro", WHITE, INK),
        ("Python 실행기", "ROS ↔ HTTP 변환", WHITE, INK),
        ("FleetService · DB", "명령·상태·로그", WHITE, INK),
        ("Vue 관제 화면", "조회·시작·정지", WHITE, INK),
    ]
    for nx, (title, detail, fill, title_color) in zip(node_xs, node_specs):
        box(page, nx, node_y, node_w, node_h, fill=fill, stroke=(184 / 255, 201 / 255, 210 / 255))
        text(page, nx + 4, node_y + 8, node_w - 8, 15, title, size=7.8, color=title_color, bold=True, align=1)
        text(page, nx + 4, node_y + 27, node_w - 8, 11, detail, size=6.1, color=MUTED, align=1)

    labels_top = ["/amcl_pose 위치 구독 · 실행 결과", "HTTP POST telemetry", "snapshot 조회 응답"]
    labels_bottom = ["NavigateToPose 목표 · 취소 요청", "HTTP GET command 응답", "시작 · 정지 요청"]
    for i in range(3):
        left = node_xs[i] + node_w
        right = node_xs[i + 1]
        arrow(page, left + 3, node_y + 16, right - 3, node_y + 16, color=CYAN)
        arrow(page, right - 3, node_y + 31, left + 3, node_y + 31, color=(105 / 255, 121 / 255, 132 / 255))
        center = (left + right) / 2
        text(page, center - 67, 137, 134, 13, labels_top[i], size=5.8, color=CYAN, bold=True, align=1)
        text(page, center - 67, 205, 134, 13, labels_bottom[i], size=5.7, color=MUTED, align=1)

    # Middle: communication tasks
    box(page, 34, 263, 382, 147)
    section_title(page, 51, 279, "통신 중심 추진 내용", width=250)
    rows = [
        ("01", "위치 수집", "/amcl_pose 구독 → telemetry 전송 → DB 반영", CYAN),
        ("02", "명령 전달", "command 조회 → NavigateToPose/Cancel 요청", BLUE),
        ("03", "상태 확인", "대기·이동·결과를 snapshot과 화면에 반영", ORANGE),
        ("04", "이력 기록", "요청 시각·상태 변화·실행 결과를 로그로 보존", GREEN),
    ]
    for idx, (num, title, detail, accent) in enumerate(rows):
        yy = 310 + idx * 22
        page.draw_circle(fitz.Point(64, yy + 7), 7, color=accent, fill=accent)
        text(page, 56, yy + 2, 16, 12, num, size=5.6, color=WHITE, bold=True, align=1)
        text(page, 80, yy, 65, 14, title, size=7.5, bold=True)
        text(page, 147, yy, 244, 14, detail, size=6.8, color=MUTED)

    # Middle: validation principles + authoritative map
    box(page, 428, 263, 380, 147)
    section_title(page, 445, 279, "실제 로봇 검증 기준", width=250)
    text(page, 445, 310, 224, 15, "로봇별 ROS Domain을 분리해 통신", size=7.8, bold=True)
    pill(page, 445, 331, 63, "62b2 · D40", fill=CYAN_SOFT, color=INK, size=6.8)
    pill(page, 514, 331, 63, "648d · D30", fill=ORANGE_SOFT, color=INK, size=6.8)
    pill(page, 583, 331, 63, "eed0 · D35", fill=(248 / 255, 235 / 255, 252 / 255), color=INK, size=6.8)
    text(
        page,
        445,
        359,
        210,
        38,
        "HTTP 접수나 navigating=false만으로 도착·정지를 확정하지 않고,\n결과 상태와 실제 위치를 함께 확인한다.",
        size=6.7,
        color=MUTED,
        lineheight=1.45,
    )
    page.draw_line(fitz.Point(667, 302), fitz.Point(667, 395), color=LINE, width=0.8)
    if MAP_IMAGE.exists():
        page.insert_image(fitz.Rect(685, 303, 733, 382), filename=str(MAP_IMAGE), keep_proportion=True)
    text(page, 741, 311, 51, 13, "선정 SLAM", size=7.1, bold=True)
    text(page, 741, 326, 51, 28, "147 × 207 px\n0.01 m/px", size=6.2, color=MUTED, lineheight=1.4)
    text(page, 683, 386, 108, 10, "지도·메타데이터 기준", size=5.9, color=MUTED, align=1)

    # Demo plan
    box(page, 34, 423, 774, 123)
    section_title(page, 51, 438, "발표 시연 계획", width=250)
    step_y = 474
    step_xs = [51, 242, 433, 624]
    number_step(page, step_xs[0], step_y, 1, "연결 상태 확인", "로봇별 online · 위치 수신", CYAN)
    number_step(page, step_xs[1], step_y, 2, "주행 명령 전달", "고정 목표 · 취소 요청", BLUE)
    number_step(page, step_xs[2], step_y, 3, "피드백 수신", "대기 · 이동 · 실행 결과", ORANGE)
    number_step(page, step_xs[3], step_y, 4, "관제 화면 기록", "snapshot · event log", GREEN)
    for sx in [228, 419, 610]:
        arrow(page, sx, step_y + 23.5, sx + 10, step_y + 23.5, color=LINE, width=2.0)
    text(page, 51, 531, 740, 10, "※ 실제 로봇 연동 전 화면 데이터는 ‘샘플’로 명확히 구분하고, 실로봇 검증 결과와 혼용하지 않는다.", size=6.3, color=MUTED)

    # Footer
    text(page, 34, 567, 520, 12, "구현 의도 설명 자료 · 로보틱스 미니프로젝트의 통신 경로와 검증 순서를 중심으로 구성", size=6.5, color=MUTED)
    text(page, 674, 567, 134, 12, "Pinky Fleet Control Team", size=6.5, color=MUTED, align=2)

    metadata = {
        "title": "Pinky Fleet Control - Robotics Mini Project Communication Plan",
        "author": "Pinky Fleet Control Team",
        "subject": "PinkyPro communication-centered interim one-pager",
        "keywords": "PinkyPro, ROS 2, Nav2, Laravel, Vue, telemetry, robotics",
    }
    doc.set_metadata(metadata)
    doc.subset_fonts()
    doc.save(OUTPUT, garbage=4, deflate=True)
    doc.close()
    print(OUTPUT)


if __name__ == "__main__":
    build()
