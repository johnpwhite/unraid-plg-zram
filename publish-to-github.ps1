# publish-to-github.ps1
# Automates the release from Private GitLab to Public GitHub
# Maintains a clean history on GitHub (one commit per release)

# --- CONFIGURATION ---
$GITHUB_USER = "johnpwhite"
$REPO_NAME = "unraid-plugin-zram"
$PLG_FILE = "unraid-zram-card.plg"
$GITHUB_RAW_URL = "https://github.com/$GITHUB_USER/$REPO_NAME/raw/main"
$GITLAB_RAW_URL = "https://gitlab.johnpwhite.com/johner/unraid-zram-card/-/raw/master"

# 1. Verification
$status = git status --porcelain
if ($status) {
    Write-Host "ERROR: You have uncommitted changes on master. Please commit or stash them first." -ForegroundColor Red
    exit
}

# 2. Extract Version
$version = (Select-String -Path $PLG_FILE -Pattern 'ENTITY version\s+"([\d\.]+)"').Matches.Groups[1].Value
Write-Host "Preparing release for Version $version ..." -ForegroundColor Cyan

# 3. Handle persistent deployment branch
if (!(git branch | Select-String "deploy-github")) {
    Write-Host "Initializing deployment branch..."
    # Create it as an empty branch if this is the first time
    git checkout --orphan deploy-github
    git rm -rf . --quiet
    git commit --allow-empty -m "Initial GitHub state"
} else {
    git checkout deploy-github
}

try {
    # 4. Sync files from master
    Write-Host "Syncing files from master..."
    # This brings in all files from master without merging history
    git checkout master -- .

    # 5. Transformations (URL Rewriting & Changelog Cleanup)
    Write-Host "Rewriting URLs and cleaning Changelog in .plg for GitHub..."
    $content = Get-Content $PLG_FILE -Raw
    $content = $content -replace [regex]::Escape($GITLAB_RAW_URL), $GITHUB_RAW_URL
    if (Test-Path "CHANGES.public.xml") {
        $publicChanges = Get-Content "CHANGES.public.xml" -Raw
        $content = $content -replace '(?s)<CHANGES>.*?</CHANGES>', $publicChanges
    }
    Set-Content $PLG_FILE $content

    # 6. File Swapping (README)
    if (Test-Path "README.public.md") {
        Write-Host "Replacing internal README with public version..."
        if (Test-Path "README.md") { Remove-Item "README.md" -Force }
        Copy-Item "README.public.md" "README.md"
    }

    # 7. Cleaning (Remove internal files)
    Write-Host "Removing internal files and temporary debug logs..."
    $internalFiles = @(
        "AGENT_SKILL_UNRAID_PLUGIN.md",
        "README.public.md",
        "CHANGES.public.xml",
        "publish-to-github.ps1",
        "debug files",
        "screen-shots",
        "release"
    )

    foreach ($file in $internalFiles) {
        if (Test-Path $file) {
            git rm -r "$file" --force --quiet 2>$null
            if (Test-Path $file) { Remove-Item -Recurse -Force $file }
        }
    }

    # 8. Commit
    Write-Host "Creating release commit..."
    git add -A
    # Only commit if there are actually changes
    if (git diff --staged) {
        git commit -m "Official Release v$version" --quiet
        
        # 9. Push to GitHub
        Write-Host "Pushing to GitHub..." -ForegroundColor Yellow
        git push github deploy-github:main --force
        Write-Host "SUCCESS: Version $version is now live on GitHub!" -ForegroundColor Green
    } else {
        Write-Host "NOTICE: No changes detected since last GitHub release. Nothing to push." -ForegroundColor Yellow
    }

}
catch {
    Write-Host "An error occurred during publishing: $($_.Exception.Message)" -ForegroundColor Red
}
finally {
    # 10. Return to master
    Write-Host "Returning to master workspace..."
    git checkout master --quiet
    Write-Host "Returned to master (GitLab branch)." -ForegroundColor Gray
}
