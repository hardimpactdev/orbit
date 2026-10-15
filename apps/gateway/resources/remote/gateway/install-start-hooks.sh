# Program: gateway.install-start-hooks
# shellcheck shell=bash
# Timeout: 60
# Input: <hook directory> <drop-in directory> <manifest>, then <name> <drop-in> <description> <program> for each hook.
# Result: no data. Before it works on a hook, it prints `orbit-start-hook=<name>` on standard error, so the last such
#   line of a failed run names the hook.
# Installs the Gateway PHP-FPM start hooks: each program in the hook directory, and a drop-in that runs it as root in
# ExecStartPre. It removes the hooks that the manifest lists and the input no longer names, then records the input in
# the manifest. Without a manifest, it removes nothing. A file that already holds its bytes, owner, and mode stays as
# it is, and systemd reloads once, only when a drop-in changed.

# Byte-wise ranges in the patterns below, whatever locale sudo passes on.
export LC_ALL=C
hooks=$1
units=$2
manifest=$3
shift 3

name_pattern='^[a-z0-9]+(-[a-z0-9]+)*-start$'
drop_in_pattern='^orbit-[a-z0-9]+(-[a-z0-9]+)*\.conf$'
description_pattern='^[!-~]([ -~]*[!-~])?$'
candidates=()
trap 'rm -f -- "${candidates[@]}"' EXIT

# Writes $3 to the file $1 with mode $2, owned by root, through a candidate and a rename that never lands inside a
# directory. A file that already holds those bytes, owner, and mode stays untouched. Sets `wrote`.
write_file() {
    wrote=0
    if [ -f "$1" ] && [ ! -L "$1" ] && [ "$(stat -c '%U:%G:%a' -- "$1")" = "root:root:$2" ] \
        && [ "$(cat -- "$1"; printf x)" = "${3}x" ]; then
        return 0
    fi
    candidate=$(mktemp "$(dirname -- "$1")/.$(basename -- "$1").XXXXXX")
    candidates+=("$candidate")
    printf '%s' "$3" > "$candidate"
    chown root:root -- "$candidate"
    chmod "$2" -- "$candidate"
    if [ "$2" = 755 ]; then
        bash -n "$candidate"
    fi
    mv -fT -- "$candidate" "$1"
    wrote=1
}

# The input: every name and drop-in is distinct, and each description is one printable line.
if [ $(( $# % 4 )) -ne 0 ]; then
    printf 'The start hook input must come in groups of name, drop-in, description, and program.\n' >&2
    exit 1
fi
names=()
drop_ins=()
descriptions=()
programs=()
declare -A wanted_names=() wanted_drop_ins=()
while [ "$#" -gt 0 ]; do
    printf 'orbit-start-hook=%s\n' "$1" >&2
    if ! [[ $1 =~ $name_pattern && $2 =~ $drop_in_pattern && $3 =~ $description_pattern ]] \
        || [ -n "${wanted_names[$1]+x}" ] || [ -n "${wanted_drop_ins[$2]+x}" ]; then
        printf 'The start hook input is invalid.\n' >&2
        exit 1
    fi
    names+=("$1")
    drop_ins+=("$2")
    descriptions+=("$3")
    programs+=("$4")
    wanted_names[$1]=1
    wanted_drop_ins[$2]=1
    shift 4
done
printf 'orbit-start-hook=\n' >&2

# The hooks this program installed before. A line that is not a valid name and drop-in stops the run unchanged.
previous=()
declare -A recorded=()
if [ -e "$manifest" ] || [ -L "$manifest" ]; then
    if [ ! -f "$manifest" ] || [ -L "$manifest" ]; then
        printf 'The start hook manifest %s is not a regular file.\n' "$manifest" >&2
        exit 1
    fi
    while IFS=' ' read -r name drop_in extra || [ -n "$name" ]; do
        if ! [[ $name =~ $name_pattern && $drop_in =~ $drop_in_pattern && -z $extra ]]; then
            printf 'The start hook manifest %s holds an invalid line.\n' "$manifest" >&2
            exit 1
        fi
        previous+=("$name $drop_in")
        recorded["$name $drop_in"]=1
    done < "$manifest"
fi

install -d -o root -g root -m 0755 -- "$hooks" "$units"

# Record every hook before it is written, so a run that stops halfway still lists each file it may have left.
text=''
for entry in "${previous[@]}"; do
    text+="$entry"$'\n'
done
for i in "${!names[@]}"; do
    if [ -z "${recorded["${names[$i]} ${drop_ins[$i]}"]+x}" ]; then
        text+="${names[$i]} ${drop_ins[$i]}"$'\n'
    fi
done
if [ -n "$text" ]; then
    write_file "$manifest" 644 "$text"
fi

units_changed=0
for i in "${!names[@]}"; do
    printf 'orbit-start-hook=%s\n' "${names[$i]}" >&2
    write_file "$hooks/${names[$i]}" 755 "${programs[$i]}"
    printf -v unit '# Managed by Orbit: %s\n[Service]\nExecStartPre=+%s/%s\n' "${descriptions[$i]}" "$hooks" "${names[$i]}"
    write_file "$units/${drop_ins[$i]}" 644 "$unit"
    if [ "$wrote" = 1 ]; then
        units_changed=1
    fi
done

# A drop-in that no hook names any more goes first. After the reload no unit runs a removed program.
for entry in "${previous[@]}"; do
    name=${entry% *}
    drop_in=${entry#* }
    if [ -z "${wanted_drop_ins[$drop_in]+x}" ] && { [ -e "$units/$drop_in" ] || [ -L "$units/$drop_in" ]; }; then
        printf 'orbit-start-hook=%s\n' "$name" >&2
        rm -f -- "$units/$drop_in"
        units_changed=1
    fi
done
if [ "$units_changed" = 1 ]; then
    systemctl daemon-reload
fi
for entry in "${previous[@]}"; do
    name=${entry% *}
    if [ -z "${wanted_names[$name]+x}" ] && { [ -e "$hooks/$name" ] || [ -L "$hooks/$name" ]; }; then
        printf 'orbit-start-hook=%s\n' "$name" >&2
        rm -f -- "$hooks/$name"
    fi
done

text=''
for i in "${!names[@]}"; do
    text+="${names[$i]} ${drop_ins[$i]}"$'\n'
done
if [ -n "$text" ] || [ -e "$manifest" ]; then
    write_file "$manifest" 644 "$text"
fi
