# Project working instructions

- Read README.md, docs/01-status.md, and docs/10-decisions.md before implementation.
- This project owns the Laravel API, task management, and Vue dashboard. A teammate owns the pathfinding algorithm. Do not silently replace or modify that teammate's implementation.
- Actual PinkyPro robots are the target. Simulation is only a development aid and must be visibly identified.
- Treat the selected SLAM map and its metadata as authoritative. Do not hard-code an 8x5 grid, 25 cm cells, a 200x130 cm map, or the earlier illustrated wall layout into the application.
- The central controller is Laravel + Vue. Existing Python/ROS robot-side components may be reused or adapted by their owner.
- Preserve the robot IDs and separate ROS domains: eed0=35, 648d=30, 62b2=40. IP addresses are environment-specific and currently unknown.
- Keep interface drafts explicitly marked until the algorithm and robot owners agree. Confirm interface compatibility before integration.
- Do not represent HTTP acceptance, goal cancellation requests, or navigating=false as physical arrival or verified stopping.
- Keep planning, execution supervision, and local motor/safety control responsibilities separate. Do not run a motor-control loop in Vue or PHP request handlers.
- Never put Wi-Fi passwords, access tokens, or private keys in tracked files or logs.
- Do not change shared/global PHP, Node, ROS, WSL, or network settings merely to prepare this project. Record and use a project-scoped environment where possible.
- Update docs/01-status.md and docs/11-work-log.md after meaningful work. Mark complete only with evidence and distinguish static review, automated tests, simulator tests, and real robot tests.
- User instructions override this file. Do not add extra approval gates through these project instructions.
