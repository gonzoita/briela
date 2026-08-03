# subir.ps1 — sube los cambios al repo y deja que el deploy automatico
# haga el resto (build + servidor). Uso:
#     .\subir.ps1 "descripcion del cambio"
#
# Hace el push de forma robusta: limpia locks colgados, integra lo que el
# deploy haya subido por su cuenta (el build compilado) y empuja todo, sin
# los enredos de "non-fast-forward" que salian antes.

param([string]$msg = "Cambios")

# 1. Limpia archivos de bloqueo colgados (por si VS Code dejo alguno)
Remove-Item .git\index.lock, .git\HEAD.lock -Force -ErrorAction SilentlyContinue

# 2. Agrega y commitea (solo si hay cambios nuevos)
git add -A
git diff --cached --quiet
if ($LASTEXITCODE -ne 0) {
    git commit -m $msg
} else {
    Write-Host "No hay cambios nuevos para commitear (quiza solo integrar el build)."
}

# 3. Integra lo que el deploy subio (build) y empuja todo
git pull --no-rebase --no-edit origin main
git push origin main

Write-Host ""
Write-Host "Listo. El deploy automatico llevara los cambios al servidor solo."
Write-Host "Podes ver el avance en GitHub -> pestana Actions."
