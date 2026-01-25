#!/usr/bin/env bash
set -euo pipefail

# Orbit Bootstrap Installer
# Usage: curl -fsSL https://raw.githubusercontent.com/hardimpactdev/orbit-cli/main/install.sh | bash

ORBIT_REPO="hardimpactdev/orbit-cli"
ORBIT_INSTALL_DIR="${ORBIT_INSTALL:-$HOME/.local/bin}"
ORBIT_LOG_FILE="${TMPDIR:-/tmp}/orbit-install.log"
PHP_VERSIONS="${PHP_VERSIONS:-8.4 8.5}"

# Colors (only if terminal supports it)
if [[ -t 1 ]]; then
    Red='\033[0;31m'
    Green='\033[0;32m'
    LimeGreen='\033[38;5;118m'
    Yellow='\033[0;33m'
    Dim='\033[0;2m'
    Bold='\033[1m'
    Reset='\033[0m'
else
    Red=''
    Green=''
    LimeGreen=''
    Yellow=''
    Dim=''
    Bold=''
    Reset=''
fi

# ASCII art logo
show_logo() {
    echo ""
    echo -e "${LimeGreen}"
    cat << 'EOF'
   ____       __    _ __ 
  / __ \_____/ /_  (_) /_
 / / / / ___/ __ \/ / __/
/ /_/ / /  / /_/ / / /_  
\____/_/  /_.___/_/\__/  
EOF
    echo -e "${Reset}"
}

# Spinner characters - use fancy if terminal supports Unicode, fallback to ASCII
if [[ "${TERM_PROGRAM:-}" == "Apple_Terminal" ]] || [[ "${LANG:-}" != *"UTF-8"* && "${LC_ALL:-}" != *"UTF-8"* ]]; then
    SPINNER='|/-\'
else
    SPINNER='⠋⠙⠹⠸⠼⠴⠦⠧⠇⠏'
fi

error() {
    printf "\r\033[K${Red}error${Reset}: %s\n" "$*" >&2
    if [[ -f "$ORBIT_LOG_FILE" ]]; then
        echo ""
        echo -e "${Dim}Log output:${Reset}"
        tail -20 "$ORBIT_LOG_FILE"
    fi
    exit 1
}

warn() {
    printf "${Yellow}warning${Reset}: %s\n" "$*" >&2
}

success() {
    printf "\r\033[K${Green}✓${Reset} %s\n" "$*"
}

# Run command with spinner, suppressing output
spin() {
    local msg="$1"
    shift
    local cmd="$@"
    local i=0
    
    # Start command in background, redirect output to log
    eval "$cmd" >> "$ORBIT_LOG_FILE" 2>&1 &
    local pid=$!
    
    # Show spinner while command runs
    while kill -0 $pid 2>/dev/null; do
        i=$(( (i + 1) % ${#SPINNER} ))
        printf "\r\033[K${Dim}${SPINNER:$i:1}${Reset} %s" "$msg"
        sleep 0.1
    done
    
    # Check exit status
    wait $pid
    local status=$?
    
    if [[ $status -eq 0 ]]; then
        success "$msg"
    else
        error "$msg failed (exit code $status)"
    fi
    
    return $status
}

# Check for required commands
command -v curl >/dev/null || error "curl is required to install Orbit"

# Detect platform
platform=$(uname -s)
arch=$(uname -m)

# Clear log file
> "$ORBIT_LOG_FILE"

# ============================================================================
# macOS Installation
# ============================================================================
install_macos() {
    # 1. Install Homebrew if missing
    if ! command -v brew >/dev/null; then
        spin "Installing Homebrew (this may take a while)" "NONINTERACTIVE=1 /bin/bash -c \"\$(curl -fsSL https://raw.githubusercontent.com/Homebrew/install/HEAD/install.sh)\""
        
        # Add Homebrew to PATH for this session
        if [[ -f /opt/homebrew/bin/brew ]]; then
            eval "$(/opt/homebrew/bin/brew shellenv)"
        elif [[ -f /usr/local/bin/brew ]]; then
            eval "$(/usr/local/bin/brew shellenv)"
        fi
    else
        success "Homebrew already installed"
    fi
    
    # 2. Install PHP versions via shivammathur/php tap
    spin "Adding PHP tap" "brew tap shivammathur/php"
    
    local first_version=""
    for version in $PHP_VERSIONS; do
        if brew list "php@$version" &>/dev/null; then
            success "PHP $version already installed"
        else
            spin "Installing PHP $version" "brew install shivammathur/php/php@$version"
        fi
        # Track first version to link as default
        if [[ -z "$first_version" ]]; then
            first_version="$version"
        fi
    done
    
    # Link first version as default CLI
    if [[ -n "$first_version" ]]; then
        spin "Linking PHP $first_version as default" "brew link --overwrite --force php@$first_version"
    fi
    
    # 3. Install Composer if missing
    if ! command -v composer >/dev/null; then
        spin "Installing Composer" "brew install composer"
    else
        success "Composer already installed"
    fi
    
    # 4. Install GitHub CLI if missing
    if ! command -v gh >/dev/null; then
        spin "Installing GitHub CLI" "brew install gh"
    else
        success "GitHub CLI already installed"
    fi
}

# ============================================================================
# Linux Installation
# ============================================================================
install_linux() {
    # Check for apt (Ubuntu/Debian only)
    if ! command -v apt-get >/dev/null; then
        error "apt package manager not found. Ubuntu/Debian required."
    fi
    
    # Check for systemd
    if [[ ! -d /etc/systemd ]]; then
        error "systemd not found. Required for service management."
    fi
    
    # 1. Add Ondrej PHP PPA if not already added
    if [[ ! -f /etc/apt/sources.list.d/ondrej-ubuntu-php-*.list ]] && [[ ! -f /etc/apt/sources.list.d/ondrej-php.list ]]; then
        spin "Updating package lists" "sudo apt-get update -qq"
        spin "Installing prerequisites" "sudo apt-get install -y software-properties-common"
        spin "Adding PHP repository" "sudo add-apt-repository -y ppa:ondrej/php"
    fi
    
    # 2. Install PHP versions
    spin "Updating package lists" "sudo apt-get update -qq"
    
    local first_version=""
    for version in $PHP_VERSIONS; do
        if dpkg -l "php$version-cli" &>/dev/null; then
            success "PHP $version already installed"
        else
            spin "Installing PHP $version" "sudo apt-get install -y php$version-cli php$version-common php$version-curl php$version-zip php$version-mbstring php$version-xml php$version-bcmath php$version-fpm"
        fi
        # Track first version to set as default
        if [[ -z "$first_version" ]]; then
            first_version="$version"
        fi
    done
    
    # Set first version as default CLI
    if [[ -n "$first_version" ]]; then
        sudo update-alternatives --set php "/usr/bin/php$first_version" >> "$ORBIT_LOG_FILE" 2>&1 || true
    fi
    
    # 3. Install Composer if missing
    if ! command -v composer >/dev/null; then
        spin "Installing Composer" "curl -sS https://getcomposer.org/installer | sudo php -- --install-dir=/usr/local/bin --filename=composer"
    else
        success "Composer already installed"
    fi
    
    # 4. Install GitHub CLI if missing
    if ! command -v gh >/dev/null; then
        spin "Installing GitHub CLI" "(type -p wget >/dev/null || sudo apt-get install wget -y) && sudo mkdir -p -m 755 /etc/apt/keyrings && wget -nv -O- https://cli.github.com/packages/githubcli-archive-keyring.gpg | sudo tee /etc/apt/keyrings/githubcli-archive-keyring.gpg > /dev/null && sudo chmod go+r /etc/apt/keyrings/githubcli-archive-keyring.gpg && echo 'deb [arch=$(dpkg --print-architecture) signed-by=/etc/apt/keyrings/githubcli-archive-keyring.gpg] https://cli.github.com/packages stable main' | sudo tee /etc/apt/sources.list.d/github-cli.list > /dev/null && sudo apt-get update -qq && sudo apt-get install gh -y"
    else
        success "GitHub CLI already installed"
    fi
}

# ============================================================================
# Download and Install Orbit
# ============================================================================
install_orbit() {
    # Create install directory
    mkdir -p "$ORBIT_INSTALL_DIR"
    
    local latest_url="https://github.com/$ORBIT_REPO/releases/latest/download/orbit.phar"
    local orbit_path="$ORBIT_INSTALL_DIR/orbit"
    
    spin "Downloading Orbit CLI" "curl -fsSL -o '$orbit_path' '$latest_url'"
    chmod +x "$orbit_path"
}

# ============================================================================
# Configure PATH
# ============================================================================
configure_path() {
    local bin_dir="$ORBIT_INSTALL_DIR"
    
    # Check if already in PATH
    if [[ ":$PATH:" == *":$bin_dir:"* ]]; then
        return 0
    fi
    
    local shell_name=$(basename "$SHELL")
    local config_file=""
    local export_line="export PATH=\"$bin_dir:\$PATH\""
    
    case "$shell_name" in
        zsh)
            config_file="$HOME/.zshrc"
            ;;
        bash)
            if [[ -f "$HOME/.bash_profile" ]]; then
                config_file="$HOME/.bash_profile"
            else
                config_file="$HOME/.bashrc"
            fi
            ;;
        fish)
            config_file="$HOME/.config/fish/config.fish"
            export_line="set -gx PATH $bin_dir \$PATH"
            ;;
        *)
            warn "Unknown shell: $shell_name. Please add $bin_dir to your PATH manually."
            return 0
            ;;
    esac
    
    # Check if already configured
    if [[ -f "$config_file" ]] && grep -q "$bin_dir" "$config_file" 2>/dev/null; then
        return 0
    fi
    
    # Add to config file
    if [[ -n "$config_file" ]]; then
        echo "" >> "$config_file"
        echo "# Orbit CLI" >> "$config_file"
        echo "$export_line" >> "$config_file"
        success "Added to PATH in $(basename "$config_file")"
    fi
    
    # Add to current session
    export PATH="$bin_dir:$PATH"
}

# ============================================================================
# Run Orbit Install
# ============================================================================
run_orbit_install() {
    local orbit_path="$ORBIT_INSTALL_DIR/orbit"
    
    if [[ ! -x "$orbit_path" ]]; then
        error "Orbit not found at $orbit_path"
    fi
    
    echo ""
    # Pass through any arguments - this runs with full output
    "$orbit_path" install "$@"
}

# ============================================================================
# Main
# ============================================================================
main() {
    show_logo
    
    case "$platform" in
        Darwin)
            install_macos
            ;;
        Linux)
            install_linux
            ;;
        *)
            error "Unsupported platform: $platform"
            ;;
    esac
    
    install_orbit
    configure_path
    
    echo ""
    echo -e "${Green}Prerequisites installed successfully!${Reset}"
    
    # Run orbit install
    run_orbit_install "$@"
}

main "$@"
