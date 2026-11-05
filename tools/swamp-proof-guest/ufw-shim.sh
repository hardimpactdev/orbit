#!/bin/sh
# Proof harness shim for ufw. The runner moves the real program to ufw.real and puts this file in its place; the fault and busy_node
# steps write the state below. Without state every call goes to the real ufw. Calls that only read always go through.
d=${PROOF_UFW_DIR:-/var/tmp/proof-ufw}
real=${PROOF_UFW_REAL:-/usr/sbin/ufw.real}
case " $* " in
  *" status "*|*" show "*|*" version "*|*" --version "*|*" help "*|*" --help "*) exec "$real" "$@" ;;
esac
# busy_node holds a lock on $d/stall: a write waits here until it is released.
[ -e "$d/stall" ] && flock "$d/stall" true
# fault: after AFTER calls that contain WORD, every write fails.
if [ -e "$d/fault" ]; then
  # shellcheck source=/dev/null
  . "$d/fault"
  if [ "$SEEN" -ge "$AFTER" ]; then
    echo 'ufw: injected fault' >&2
    exit 1
  fi
  case " $* " in
    *"$WORD"*) printf 'WORD=%s\nAFTER=%s\nSEEN=%s\n' "$WORD" "$AFTER" $((SEEN + 1)) > "$d/fault" ;;
  esac
fi
exec "$real" "$@"
