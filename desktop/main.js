"use strict";
const { app, BrowserWindow, ipcMain, shell } = require("electron");
const { readFileSync, writeFileSync, mkdirSync, existsSync, chmodSync } = require("node:fs");
const { join } = require("node:path");
const { homedir } = require("node:os");

const CFG = JSON.parse(readFileSync(join(__dirname, "app-config.json"), "utf8"));
const API_URL = CFG.api_url.replace(/\/$/, "");   // le service Pegasus (Worker Cloudflare)
const API_KEY = CFG.api_key;                          // jeton public : il n'échange qu'un code contre la clé
const KEY_DIR = join(homedir(), ".pegasus");
const KEY_FILE = join(KEY_DIR, "team-key");

let win;

function createWindow() {
  win = new BrowserWindow({
    width: 520,
    height: 640,
    resizable: false,
    title: "Pegasus",
    backgroundColor: "#0b0e14",
    webPreferences: {
      preload: join(__dirname, "preload.js"),
      contextIsolation: true,
      nodeIntegration: false,
    },
  });
  win.loadFile(join(__dirname, "renderer", "index.html"));
  // Liens externes → navigateur système
  win.webContents.setWindowOpenHandler(({ url }) => {
    shell.openExternal(url);
    return { action: "deny" };
  });
}

// Est-ce que la clé est déjà installée ?
ipcMain.handle("status", () => ({ installed: existsSync(KEY_FILE) }));

// Récupère la clé d'équipe avec un code d'accès, puis l'écrit localement.
ipcMain.handle("connect", async (_e, code) => {
  code = String(code || "").trim().toUpperCase();
  if (!code) return { ok: false, error: "Entre ton code d'accès." };

  let res;
  try {
    res = await fetch(`${API_URL}/v1/rpc/get_team_key`, {
      method: "POST",
      headers: {
        Authorization: `Bearer ${API_KEY}`,
        "Content-Type": "application/json",
      },
      body: JSON.stringify({ p_code: code }),
    });
  } catch (e) {
    return { ok: false, error: "Pas de connexion internet ? (" + e.message + ")" };
  }

  const text = await res.text();
  if (!res.ok) {
    let msg = "Code invalide ou révoqué.";
    try {
      const j = JSON.parse(text);
      if (j.message) msg = j.message;
    } catch {}
    return { ok: false, error: msg };
  }

  // Le service renvoie le blob sous forme de chaîne JSON (avec guillemets).
  let teamKey = text.trim();
  try {
    const parsed = JSON.parse(teamKey);
    if (typeof parsed === "string") teamKey = parsed;
  } catch {}
  teamKey = teamKey.trim();

  if (!teamKey || teamKey.length < 100) {
    return { ok: false, error: "Réponse inattendue du serveur. Préviens Sacha." };
  }

  // Vérifie que le blob est décodable (base64 → JSON attendu)
  try {
    const decoded = JSON.parse(Buffer.from(teamKey, "base64").toString("utf8"));
    if (!decoded.api_url || !decoded.api_token || !decoded.private_key) throw new Error("incomplet");
  } catch {
    return { ok: false, error: "La clé reçue est invalide. Préviens Sacha." };
  }

  try {
    if (!existsSync(KEY_DIR)) mkdirSync(KEY_DIR, { recursive: true });
    chmodSync(KEY_DIR, 0o700);
    writeFileSync(KEY_FILE, teamKey, { mode: 0o600 });
    chmodSync(KEY_FILE, 0o600);
  } catch (e) {
    return { ok: false, error: "Impossible d'écrire la clé : " + e.message };
  }

  return { ok: true };
});

ipcMain.handle("open-external", (_e, url) => shell.openExternal(url));

app.whenReady().then(createWindow);
app.on("window-all-closed", () => app.quit());
app.on("activate", () => {
  if (BrowserWindow.getAllWindows().length === 0) createWindow();
});
