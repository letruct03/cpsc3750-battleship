# CPSC 3750 – Battleship Phase 1

**Distributed Multiplayer Battleship — Server + Database**
Stack: PHP 8.x · MySQL · MAMP (local)

---

## Team

| Name | Role |
|------|------|
| Truc Le | Debugger |
| Evan Racz | Backend and DB development |

AI Tools Used: Claude (claude.ai)

---

## Architecture Overview

```
Browser / curl / autograder
        |
        v
   index.php  ← front controller, URL router
        |
   ┌────┴────────────────────────────┐
   │  api/         test/             │
   │  players.php  test_endpoints.php│
   │  games.php                      │
   │  ships.php                      │
   │  moves.php                      │
   └────────────────┬────────────────┘
                    │
             config/db.php  ← PDO singleton (MAMP MySQL :8889)
             config/helpers.php
                    │
              MySQL: battleship DB
              ┌──────────────┐
              │ Players      │
              │ Games        │
              │ GamePlayers  │ ← join table (1-N players per game)
              │ Moves        │ ← append-only shot log
              └──────────────┘
```

### State Map

| Data | Lives In | Persists? | Source of Truth |
|------|----------|-----------|-----------------|
| Player accounts & stats | `Players` table | ✅ Yes | Database |
| Game status & turn | `Games` table | ✅ Yes | Database |
| Ship placements | `GamePlayers.ships_json` | ✅ Yes | Database |
| Board hit/miss grid | `GamePlayers.board_json` | ✅ Yes | Database |
| Move history | `Moves` table | ✅ Yes | Database |
| PDO connection | PHP static variable | ❌ Per-request | `config/db.php` |

---

## Local Setup (MAMP)

### 1. Clone & Place Files

```bash
git clone <your-repo-url> battleship
# Move battleship/ into your MAMP htdocs directory
# e.g. /Applications/MAMP/htdocs/battleship
```

### 2. Start MAMP

- Start Apache + MySQL via MAMP control panel
- MySQL runs on port **8889**, Apache on **8888** by default

### 3. Create Database

Open MAMP phpMyAdmin (`http://localhost:8888/phpMyAdmin`) or use MySQL CLI:

```bash
/Applications/MAMP/Library/bin/mysql -u root -proot --port=8889
```

Then run:

```sql
SOURCE /Applications/MAMP/htdocs/battleship/db/schema.sql;
```

### 4. Configure (if needed)

Edit `config/db.php` if your MAMP password differs from the default `root`.

### 5. Enable mod_rewrite

Ensure Apache mod_rewrite is enabled in MAMP. The `.htaccess` file handles routing.

### 6. Test It

```bash
curl -X POST http://localhost:8888/battleship/players \
  -H "Content-Type: application/json" \
  -d '{"username":"alice"}'
```

---

## API Reference

Base URL: `http://localhost:8888/battleship`

### Players

| Method | Endpoint | Description |
|--------|----------|-------------|
| POST | `/players` | Create player |
| GET | `/players` | List all players |
| GET | `/players/{id}` | Get player + stats |

**POST /players**
```json
Request:  { "username": "alice" }
Response: { "player_id": 1, "username": "alice", "wins": 0, ... }
```

### Games

| Method | Endpoint | Description |
|--------|----------|-------------|
| POST | `/games` | Create game |
| GET | `/games` | List games (optional ?status=waiting) |
| GET | `/games/{id}` | Get game state + players |
| POST | `/games/{id}/join` | Join a game |
| POST | `/games/{id}/ships` | Place ships |
| POST | `/games/{id}/start` | Start game |
| POST | `/games/{id}/move` | Fire a shot |
| GET | `/games/{id}/moves` | Get move history |

**POST /games**
```json
Request:  { "player_id": 1, "grid_size": 10 }
Response: { "game_id": 1, "status": "waiting", ... }
```

**POST /games/{id}/ships**
```json
Request: {
  "player_id": 1,
  "ships": [
    { "type": "carrier",    "row": 0, "col": 0, "orientation": "H" },
    { "type": "battleship", "row": 1, "col": 0, "orientation": "H" },
    { "type": "cruiser",    "row": 2, "col": 0, "orientation": "H" },
    { "type": "submarine",  "row": 3, "col": 0, "orientation": "H" },
    { "type": "destroyer",  "row": 4, "col": 0, "orientation": "H" }
  ]
}
```

**POST /games/{id}/move**
```json
Request:  { "attacker_id": 1, "target_id": 2, "row": 3, "col": 4 }
Response: { "result": "hit|miss|sunk", "next_turn": 2, ... }
```

### Ship Types

| Type | Size |
|------|------|
| carrier | 5 |
| battleship | 4 |
| cruiser | 3 |
| submarine | 3 |
| destroyer | 2 |

---

## Test-Mode Endpoints (Autograder Only)

All require header: `X-Test-Mode: cpsc3750testmode`
Returns `403` if missing or incorrect.

| Method | Endpoint | Description |
|--------|----------|-------------|
| POST | `/test/games/{id}/ships` | Deterministic ship placement |
| GET | `/test/games/{id}/board?playerId=...` | Reveal full board state |
| POST | `/test/games/{id}/reset` | Reset game (preserve stats) |
| POST | `/test/games/{id}/set-turn` | Force turn to player |

**POST /test/games/{id}/ships**
```json
Request: {
  "playerId": 1,
  "ships": [
    { "type": "destroyer", "coordinates": [[0,0],[0,1]] }
  ]
}
```

**GET /test/games/{id}/board?playerId=1**
```json
Response: {
  "ships": [ { "type": "carrier", "coordinates": [...], "hits": [...], "sunk": false } ],
  "board": [[0,0,...], ...]   // 0=untouched, 1=miss, 2=hit
}
```

---

## Testing Strategy

Run these with curl or a REST client (Postman, Insomnia).

### Smoke Test Sequence

```bash
# 1. Create two players
curl -X POST .../players -d '{"username":"alice"}'
curl -X POST .../players -d '{"username":"bob"}'

# 2. Create game (alice = player 1)
curl -X POST .../games -d '{"player_id":1,"grid_size":10}'

# 3. Bob joins
curl -X POST .../games/1/join -d '{"player_id":2}'

# 4. Both place ships (use test endpoint for determinism)
curl -X POST .../test/games/1/ships \
  -H "X-Test-Mode: cpsc3750testmode" \
  -d '{"playerId":1,"ships":[...]}'

# 5. Start game
curl -X POST .../games/1/start -d '{"player_id":1}'

# 6. Fire shots
curl -X POST .../games/1/move -d '{"attacker_id":1,"target_id":2,"row":0,"col":0}'

# 7. Verify board
curl ".../test/games/1/board?playerId=2" -H "X-Test-Mode: cpsc3750testmode"

# 8. Get move history
curl .../games/1/moves
```

### Regression Checklist (run after every code change)

- [ ] Player creation (valid + duplicate username)
- [ ] Game creation and listing
- [ ] Join logic (invalid game, already joined, game active)
- [ ] Ship placement (bounds, overlap, wrong types)
- [ ] Turn enforcement (wrong player fires → 403)
- [ ] Hit / miss / sunk results correct
- [ ] Elimination and game-over logic
- [ ] Winner stats updated correctly
- [ ] Test-mode 403 on bad/missing header
- [ ] Test reset preserves player stats

---

## Database Schema Snapshot

```
Players      (player_id PK, username UNIQUE, wins, losses, total_shots, total_hits)
Games        (game_id PK, grid_size, status ENUM, current_turn FK, winner_id FK)
GamePlayers  (gp_id PK, game_id FK, player_id FK, turn_order, ships_json, board_json, is_eliminated)
Moves        (move_id PK, game_id FK, attacker_id FK, target_id FK, coord_row, coord_col, result ENUM, ship_type, fired_at)
```

---

## Decision Log

| Decision | Reason | Alternatives Considered | Tradeoff |
|----------|--------|------------------------|---------|
| PDO with prepared statements | Prevent SQL injection; standard PHP DB access | mysqli | PDO is more portable |
| FOR UPDATE lock on Games during moves | Prevent race conditions on concurrent shots | Application-level mutex | Slightly slower but safe |
| JSON columns for ships/board | Flexible ship layouts without extra tables | Separate Ships/Board tables | Less normalized but simpler for this scope |
| Append-only Moves table | Audit trail; never lose game history | In-memory only | More storage, full replay possible |
| Turn rotation in PHP (not DB trigger) | Easier to test and debug | DB stored procedure | More control, easier regression testing |
