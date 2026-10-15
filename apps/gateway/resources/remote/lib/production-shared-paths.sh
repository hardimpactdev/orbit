# shellcheck shell=bash
# Shared directory preparation for the legacy production deployment program.
# Args: user, home, release, web root, application suffix, base64 path list.
link_shared_paths() {
    local user=$1 home=$2 release=$3 relative_root=$4 application_suffix=$5 shared_paths=$6
    local path source target entry seed nested application storage relative
    while IFS= read -r path; do
        [ -n "$path" ] || continue
        case "$relative_root" in "$path"|"$path"/*) exit 1 ;; esac
        source="$release/$path"
        target="$home/$path"
        # Check before mkdir and before removal: neither side may traverse a link.
        test "$(sudo -u "$user" -H realpath -m -- "$source")" = "$source"
        test "$(sudo -u "$user" -H realpath -m -- "$target")" = "$target"
        sudo -u "$user" -H test ! -L "$source"
        sudo -u "$user" -H test ! -L "$target"
        if sudo -u "$user" -H test -e "$source"; then
            sudo -u "$user" -H test -d "$source"
        fi
        sudo -u "$user" -H mkdir -p -- "$target"
        test "$(sudo -u "$user" -H realpath -e -- "$target")" = "$target"
        entry=$(sudo -u "$user" -H find -P "$target" -mindepth 1 -maxdepth 1 -print -quit)
        if [ -z "$entry" ] && sudo -u "$user" -H test -d "$source"; then
            # Publish a complete seed at once. A failed copy leaves the empty target for a retry.
            (
                seed=$(sudo -u "$user" -H mktemp -d "${target}.seed.XXXXXXXX")
                trap 'sudo -u "$user" -H rm -rf -- "$seed"' EXIT
                nested=$(sudo -u "$user" -H find -P "$source" -type l -print -quit)
                test -z "$nested"
                sudo -u "$user" -H cp -a -- "$source/." "$seed/"
                sudo -u "$user" -H mv -T -- "$seed" "$target"
            )
        fi
        sudo -u "$user" -H mkdir -p -- "$(dirname -- "$source")"
        sudo -u "$user" -H rm -rf -- "$source"
        sudo -u "$user" -H ln -s -- "$target" "$source"
    done < <(printf '%s' "$shared_paths" | base64 --decode; printf '\n')
    application="$release$application_suffix"
    storage="$application/storage"
    if sudo -u "$user" -H test -L "$storage" && sudo -u "$user" -H test -d "$application/public"; then
        relative=${application#"$release"}
        target="$home$relative/storage/app/public"
        test "$(sudo -u "$user" -H realpath -m -- "$storage/app/public")" = "$target"
        sudo -u "$user" -H mkdir -p -- "$target"
        if ! sudo -u "$user" -H test -e "$application/public/storage" && ! sudo -u "$user" -H test -L "$application/public/storage"; then
            sudo -u "$user" -H ln -s -- ../storage/app/public "$application/public/storage"
        fi
    fi
}
