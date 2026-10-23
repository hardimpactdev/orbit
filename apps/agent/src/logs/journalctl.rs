//! Follows one systemd unit through `journalctl` (ADR 0153). The agent runs no program that changes the
//! Node; this read-only `journalctl`, with fixed arguments, is the only program it runs. It runs
//! `journalctl --unit <unit> --output short-iso --utc` and cuts each entry by one rule, which the one-shot read of
//! `processes.logs` shares, so a live line and a line of the one-shot read are the same text.
use super::{
    laravel::Unavailable,
    limits::{LineSink, FIRST_LINES_BYTES},
};
use serde_json::Value;
use std::{path::Path, process::Stdio};
use tokio::{
    io::{AsyncBufRead, AsyncBufReadExt, AsyncReadExt, BufReader},
    process::{Child, ChildStdout, Command},
};

/// systemd's `journalctl`, present on every supported Ubuntu Node.
pub const JOURNALCTL: &str = "/usr/bin/journalctl";
/// The most bytes of lines one entry makes, indent included. The one-shot read cuts at the same place.
pub const MAX_ENTRY_TEXT_BYTES: usize = 256 * 1024;
pub const MESSAGE_CUT: &str = "[orbit] message cut at 256 KiB";
/// The most bytes of one printed line the agent keeps. The rest of a longer line is counted but not
/// kept, so a huge message cannot exhaust memory. This leaves room for a line's prefix and the 256 KiB
/// of its message that the cut keeps.
pub const MAX_LINE_READ: usize = 2 * MAX_ENTRY_TEXT_BYTES;
/// The cursor query prints one small JSON object of journalctl's own fields.
const MAX_CURSOR_OUTPUT: u64 = 4 * 1024;
const MAX_CURSOR_BYTES: usize = 1024;
const READ_BUFFER: usize = 64 * 1024;

/// Runs a `journal` source until `journalctl` cannot start or ends.
pub async fn run(unit: String, sink: &mut LineSink) -> Result<(), Unavailable> {
    run_with(Path::new(JOURNALCTL), &unit, sink).await
}

/// Sends the first lines, then follows. The newest entry's cursor splits the two: the first lines end
/// at that entry and the follow starts after it, so an entry written in between is sent exactly once.
pub async fn run_with(program: &Path, unit: &str, sink: &mut LineSink) -> Result<(), Unavailable> {
    let cursor = newest_cursor(program, unit).await?;
    let first = match &cursor {
        Some(cursor) => first_lines(program, unit, cursor, sink.lines()).await?,
        None => Vec::new(),
    };
    sink.first(first);
    follow(program, unit, cursor.as_deref(), sink).await
}

/// Starts `journalctl` for the unit. It gets no environment and no input, and it is killed when its
/// handle is dropped, so a stream that stops leaves no process behind.
fn start(
    program: &Path,
    unit: &str,
    options: &[&str],
) -> Result<(Child, BufReader<ChildStdout>), Unavailable> {
    let mut child = Command::new(program)
        .args(["--no-pager", "--quiet"])
        .arg(format!("--unit={unit}"))
        .args(options)
        .env_clear()
        .stdin(Stdio::null())
        .stdout(Stdio::piped())
        .stderr(Stdio::null())
        .kill_on_drop(true)
        .spawn()
        .map_err(|_| Unavailable("journalctl"))?;
    let stdout = child.stdout.take().ok_or(Unavailable("journalctl"))?;
    Ok((child, BufReader::with_capacity(READ_BUFFER, stdout)))
}

/// Stops a `journalctl` that may still print, and waits for it so that no zombie stays behind.
async fn stop(mut child: Child) {
    let _ = child.start_kill();
    let _ = child.wait().await;
}

/// Waits for a `journalctl` that printed everything it had, and fails when it did not succeed.
async fn finish(mut child: Child) -> Result<(), Unavailable> {
    match child.wait().await {
        Ok(status) if status.success() => Ok(()),
        _ => Err(Unavailable("journalctl output")),
    }
}

/// The cursor of the unit's newest entry, or `None` when the journal holds no entry of the unit.
async fn newest_cursor(program: &Path, unit: &str) -> Result<Option<String>, Unavailable> {
    let (child, stdout) = start(
        program,
        unit,
        &["--lines=1", "--output=json", "--output-fields=__CURSOR"],
    )?;
    let mut output = Vec::new();
    let read = stdout
        .take(MAX_CURSOR_OUTPUT + 1)
        .read_to_end(&mut output)
        .await;
    if read.is_err() || output.len() as u64 > MAX_CURSOR_OUTPUT {
        stop(child).await;
        return Err(Unavailable("journalctl output"));
    }
    finish(child).await?;
    if output.iter().all(u8::is_ascii_whitespace) {
        return Ok(None);
    }
    serde_json::from_slice::<Value>(&output)
        .ok()
        .and_then(|entry| entry.get("__CURSOR")?.as_str().map(str::to_owned))
        .filter(|cursor| {
            !cursor.is_empty()
                && cursor.len() <= MAX_CURSOR_BYTES
                && cursor.bytes().all(|b| b.is_ascii_graphic())
        })
        .map(Some)
        .ok_or(Unavailable("journal cursor"))
}

/// The unit's newest lines, oldest first: the entries from the one at `cursor` backwards, until they
/// make `lines` lines or 256 KiB. `journalctl` is stopped there, so a long history is not read to its
/// end.
async fn first_lines(
    program: &Path,
    unit: &str,
    cursor: &str,
    lines: usize,
) -> Result<Vec<String>, Unavailable> {
    let (child, stdout) = start(
        program,
        unit,
        &[
            "--reverse",
            &format!("--lines={lines}"),
            &format!("--cursor={cursor}"),
            "--output=short-iso",
            "--utc",
        ],
    )?;
    let mut reader = LineReader(stdout);
    let mut history = History::new(lines);
    let mut cut = EntryCut::default();
    let mut entry = Vec::new();
    loop {
        let Some(line) = reader.next().await? else {
            history.add(entry);
            finish(child).await?;
            return Ok(history.into_lines());
        };
        // A first line completes the entry before it.
        let mut full = !line.continues() && history.add(std::mem::take(&mut entry));
        if !full {
            cut.push(&line, &mut entry);
            // A cut entry takes no more lines, so it is complete too.
            full = cut.cut && history.add(std::mem::take(&mut entry));
        }
        if full {
            stop(child).await;
            return Ok(history.into_lines());
        }
    }
}

/// Follows the unit after the entry at `cursor`, or from its first entry when it had none. Returns when
/// `journalctl` ends, which ends the stream.
async fn follow(
    program: &Path,
    unit: &str,
    cursor: Option<&str>,
    sink: &mut LineSink,
) -> Result<(), Unavailable> {
    let from = cursor.map_or_else(
        || "--lines=all".to_owned(),
        |cursor| format!("--after-cursor={cursor}"),
    );
    let (child, stdout) = start(
        program,
        unit,
        &["--follow", &from, "--output=short-iso", "--utc"],
    )?;
    let mut reader = LineReader(stdout);
    let mut cut = EntryCut::default();
    let mut lines = Vec::new();
    while let Ok(Some(line)) = reader.next().await {
        cut.push(&line, &mut lines);
        for line in lines.drain(..) {
            sink.line(&line);
        }
    }
    stop(child).await;
    Err(Unavailable("journalctl output"))
}

/// Entries newest first, until they hold `lines` lines or 256 KiB.
struct History {
    entries: Vec<Vec<String>>,
    want: usize,
    lines: usize,
    bytes: usize,
}
impl History {
    fn new(want: usize) -> Self {
        Self {
            entries: Vec::new(),
            want,
            lines: 0,
            bytes: 0,
        }
    }

    /// Adds a complete entry and tells whether the history is full.
    fn add(&mut self, entry: Vec<String>) -> bool {
        if !entry.is_empty() {
            self.lines += entry.len();
            self.bytes += entry.iter().map(String::len).sum::<usize>();
            self.entries.push(entry);
        }
        self.lines >= self.want || self.bytes >= FIRST_LINES_BYTES
    }

    fn into_lines(self) -> Vec<String> {
        self.entries.into_iter().rev().flatten().collect()
    }
}

/// One printed line: at most its first `MAX_LINE_READ` bytes, and its full length.
#[derive(Debug)]
struct Line {
    kept: Vec<u8>,
    len: usize,
}
impl Line {
    /// journalctl indents each further line of a message, so only an entry's first line starts with
    /// something other than a space.
    fn continues(&self) -> bool {
        self.kept.first() == Some(&b' ')
    }
}

/// Reads printed lines with a bounded buffer.
struct LineReader<R>(R);
impl<R: AsyncBufRead + Unpin> LineReader<R> {
    async fn next(&mut self) -> Result<Option<Line>, Unavailable> {
        let mut line = Line {
            kept: Vec::new(),
            len: 0,
        };
        loop {
            let available = self
                .0
                .fill_buf()
                .await
                .map_err(|_| Unavailable("journalctl output"))?;
            if available.is_empty() {
                return Ok((line.len > 0).then_some(line));
            }
            let (piece, used, complete) = match available.iter().position(|b| *b == b'\n') {
                Some(end) => (&available[..end], end + 1, true),
                None => (available, available.len(), false),
            };
            let room = MAX_LINE_READ.saturating_sub(line.kept.len());
            line.kept.extend_from_slice(&piece[..piece.len().min(room)]);
            line.len += piece.len();
            self.0.consume(used);
            if complete {
                return Ok(Some(line));
            }
        }
    }
}

/// The one-shot read of `processes.logs`: the unit's newest `lines` entries, newest first, each cut as a live entry
/// is, and stopped after `max_bytes` bytes. `journalctl` is stopped there, so the Node does bounded work however long
/// an entry is. Its exit status does not count: a read that stopped early killed it.
pub fn read_newest(
    program: &Path,
    unit: &str,
    lines: usize,
    max_bytes: usize,
) -> Result<Vec<u8>, Unavailable> {
    let mut child = std::process::Command::new(program)
        .args(["--no-pager", "--quiet"])
        .arg(format!("--unit={unit}"))
        .args([
            "--reverse",
            &format!("--lines={lines}"),
            "--output=short-iso",
            "--utc",
        ])
        .env_clear()
        .stdin(Stdio::null())
        .stdout(Stdio::piped())
        .stderr(Stdio::null())
        .spawn()
        .map_err(|_| Unavailable("journalctl"))?;
    let stdout = child.stdout.take().ok_or(Unavailable("journalctl"))?;
    let output = newest_cut(
        std::io::BufReader::with_capacity(READ_BUFFER, stdout),
        max_bytes,
    );
    let _ = child.kill();
    let _ = child.wait();
    output.map_err(|_| Unavailable("journalctl output"))
}

/// Cuts journalctl's printed lines by `EntryCut` into newline-ended lines, and stops after `max_bytes` bytes.
fn newest_cut<R: std::io::BufRead>(mut reader: R, max_bytes: usize) -> std::io::Result<Vec<u8>> {
    let mut cut = EntryCut::default();
    let mut lines = Vec::new();
    let mut output = Vec::new();
    while let Some(line) = next_line(&mut reader)? {
        cut.push(&line, &mut lines);
        for line in lines.drain(..) {
            output.extend_from_slice(line.as_bytes());
            output.push(b'\n');
            if output.len() >= max_bytes {
                output.truncate(max_bytes);
                return Ok(output);
            }
        }
    }
    Ok(output)
}

/// `LineReader::next` for a blocking reader.
fn next_line<R: std::io::BufRead>(reader: &mut R) -> std::io::Result<Option<Line>> {
    let mut line = Line {
        kept: Vec::new(),
        len: 0,
    };
    loop {
        let available = reader.fill_buf()?;
        if available.is_empty() {
            return Ok((line.len > 0).then_some(line));
        }
        let (piece, used, complete) = match available.iter().position(|b| *b == b'\n') {
            Some(end) => (&available[..end], end + 1, true),
            None => (available, available.len(), false),
        };
        let room = MAX_LINE_READ.saturating_sub(line.kept.len());
        line.kept.extend_from_slice(&piece[..piece.len().min(room)]);
        line.len += piece.len();
        reader.consume(used);
        if complete {
            return Ok(Some(line));
        }
    }
}

/// Cuts each entry, for the live stream and the one-shot read alike:
/// it counts bytes, indent included, keeps the first line, and stops at the first further line that
/// would take the entry past 256 KiB. A first line whose message is longer than 256 KiB is cut there,
/// before a character rather than inside one. A cut entry ends with `[orbit] message cut at 256 KiB`,
/// indented to the message column.
#[derive(Debug, Default)]
struct EntryCut {
    used: usize,
    indent: usize,
    /// The current entry was cut, so it takes no more lines.
    cut: bool,
}
impl EntryCut {
    fn push(&mut self, line: &Line, out: &mut Vec<String>) {
        if line.continues() {
            if self.cut {
                return;
            }
            if self.used + line.len > MAX_ENTRY_TEXT_BYTES {
                self.mark_cut(out);
                return;
            }
            self.used += line.len;
            out.push(text(&line.kept));
            return;
        }
        let kept = &line.kept;
        // The message starts after the first `: `.
        let message = kept
            .windows(2)
            .position(|pair| pair == b": ")
            .map(|colon| colon + 2);
        // One column for each character of the prefix.
        self.indent = message.map_or(0, |start| {
            kept[..start].iter().filter(|b| **b & 0xc0 != 0x80).count()
        });
        self.used = line.len;
        self.cut = false;
        match message {
            Some(start) if line.len - start > MAX_ENTRY_TEXT_BYTES => {
                let mut keep = MAX_ENTRY_TEXT_BYTES.min(kept.len() - start);
                while keep > 0 && kept.get(start + keep).is_some_and(|b| b & 0xc0 == 0x80) {
                    keep -= 1;
                }
                out.push(text(&kept[..start + keep]));
                self.mark_cut(out);
            }
            _ => out.push(text(kept)),
        }
    }

    fn mark_cut(&mut self, out: &mut Vec<String>) {
        out.push(format!("{}{MESSAGE_CUT}", " ".repeat(self.indent)));
        self.cut = true;
    }
}

/// journalctl prints valid UTF-8; a line cut at `MAX_LINE_READ` may end inside a character.
fn text(bytes: &[u8]) -> String {
    String::from_utf8_lossy(bytes).into_owned()
}

#[cfg(test)]
mod tests {
    use super::*;
    use crate::logs::limits::{agent_bucket, StreamQueue, MAX_LINE_BYTES};
    use std::{path::PathBuf, sync::Arc, time::Duration};
    use tokio::{task::JoinHandle, time::Instant};

    const UNIT: &str = "orbit-process-41-queue.service";

    fn entry(second: u32, message: &str) -> String {
        format!("2026-09-25T10:15:{second:02}+00:00 beast queue[4242]: {message}")
    }

    /// A test directory with a link to the stand-in `journalctl` and the snippets it runs for the
    /// cursor query, the first lines, and the follow.
    struct Fake(PathBuf);
    impl Fake {
        fn new(cursor: &str, history: &str, follow: &str) -> Self {
            let dir = std::env::temp_dir().join(format!(
                "orbit-agent-journalctl-{}-{}",
                std::process::id(),
                rand::random::<u64>()
            ));
            std::fs::create_dir_all(&dir).unwrap();
            std::os::unix::fs::symlink(
                concat!(env!("CARGO_MANIFEST_DIR"), "/tests/fake-journalctl"),
                dir.join("journalctl"),
            )
            .unwrap();
            assert!(std::process::Command::new("mkfifo")
                .arg(dir.join("barrier"))
                .status()
                .unwrap()
                .success());
            for (name, body) in [("cursor", cursor), ("history", history), ("follow", follow)] {
                // A quiet assertion sends a barrier through the follower's stdout, after all
                // journal entries. Reading it proves the source drained those entries.
                let body = if name == "follow" {
                    body.replace(
                        "exec sleep 600",
                        "exec 3<> \"$D/barrier\"; while IFS= read -r line <&3; do printf '%s\\n' \"$line\"; done",
                    )
                } else {
                    body.to_owned()
                };
                std::fs::write(dir.join(name), body).unwrap();
            }
            Self(dir)
        }
        fn program(&self) -> PathBuf {
            self.0.join("journalctl")
        }
        fn file(&self, name: &str) -> PathBuf {
            self.0.join(name)
        }
        /// The arguments of each start, in order.
        fn calls(&self) -> Vec<String> {
            std::fs::read_to_string(self.file("calls"))
                .unwrap_or_default()
                .lines()
                .map(str::to_owned)
                .collect()
        }
        /// The arguments of each start, once there are `count` of them.
        async fn calls_after(&self, count: usize) -> Vec<String> {
            let deadline = Instant::now() + Duration::from_secs(10);
            loop {
                let calls = self.calls();
                if calls.len() >= count {
                    return calls;
                }
                assert!(Instant::now() < deadline, "{calls:?}");
                tokio::time::sleep(Duration::from_millis(20)).await;
            }
        }
        /// The process ID a snippet wrote with `echo $$ > "$D/<name>"`.
        async fn pid(&self, name: &str) -> u32 {
            let deadline = Instant::now() + Duration::from_secs(10);
            loop {
                let written = std::fs::read_to_string(self.file(name)).unwrap_or_default();
                if let Ok(pid) = written.trim().parse() {
                    return pid;
                }
                assert!(Instant::now() < deadline, "no {name}");
                tokio::time::sleep(Duration::from_millis(20)).await;
            }
        }
    }
    impl Drop for Fake {
        fn drop(&mut self) {
            let _ = std::fs::remove_dir_all(&self.0);
        }
    }

    /// The journal file of `journal_snippets`: one `cursor<TAB>line` per entry.
    fn write_journal(fake: &Fake, entries: &[(&str, String)]) {
        let text: String = entries
            .iter()
            .map(|(cursor, line)| format!("{cursor}\t{line}\n"))
            .collect();
        std::fs::write(fake.file("journal"), text).unwrap();
    }

    /// A follow that prints the entries of `$D/journal` after `--after-cursor`, or all of them with
    /// `--lines=all`, then waits, as `journalctl --follow` does.
    const FOLLOW_JOURNAL: &str = r#"
awk -F '\t' -v c="$after" 'c == "" || seen { print $2 } $1 == c { seen = 1 }' "$D/journal"
exec sleep 600
"#;

    struct Stream {
        queue: Arc<StreamQueue>,
        task: JoinHandle<Result<(), Unavailable>>,
    }
    fn stream(fake: &Fake, lines: usize) -> Stream {
        let queue = Arc::new(StreamQueue::new("a".repeat(32), agent_bucket()));
        let mut sink = LineSink::new(queue.clone(), lines);
        let program = fake.program();
        let task = tokio::spawn(async move { run_with(&program, UNIT, &mut sink).await });
        Stream { queue, task }
    }

    fn sent(flush: &crate::logs::limits::Flush) -> Vec<String> {
        flush
            .frames
            .iter()
            .filter(|frame| frame.event == "client-log")
            .flat_map(|frame| frame.data["lines"].as_array().unwrap().clone())
            .map(|line| line.as_str().unwrap().to_owned())
            .collect()
    }

    /// Flushes the queue as the realtime connection does until `count` lines were sent.
    async fn lines(queue: &StreamQueue, count: usize) -> Vec<String> {
        let mut lines = Vec::new();
        tokio::time::timeout(Duration::from_secs(10), async {
            let mut tick = tokio::time::interval(Duration::from_millis(10));
            while lines.len() < count {
                tick.tick().await;
                let flush = queue.flush("c", Instant::now()).unwrap();
                assert!(!flush.finished, "the source ended before {count} lines");
                lines.extend(sent(&flush));
            }
        })
        .await
        .unwrap_or_else(|_| panic!("{} of {count} lines", lines.len()));
        lines
    }

    /// A barrier follows every preceding entry through the real stdout pipe and LineSink.
    /// Any duplicate line or end before it fails, without guessing how long startup takes.
    async fn quiet(stream: &Stream, fake: &Fake) {
        let barrier = entry(59, "quiet barrier");
        let mut writer = tokio::process::Command::new("sh");
        writer
            .args(["-c", "printf '%s\\n' \"$2\" > \"$1\"", "barrier"])
            .arg(fake.file("barrier"))
            .arg(&barrier)
            .kill_on_drop(true);
        let output = tokio::time::timeout(Duration::from_secs(10), writer.output())
            .await
            .expect("follower reads the barrier")
            .unwrap();
        assert!(output.status.success());
        assert_eq!(lines(&stream.queue, 1).await, [barrier]);
        let flush = stream.queue.flush("c", Instant::now()).unwrap();
        assert!(flush.frames.is_empty(), "{:?}", flush.frames);
        assert!(!stream.task.is_finished());
    }

    /// Waits until the process is gone: killed and reaped, so neither it nor a zombie remains.
    async fn assert_gone(pid: u32) {
        let deadline = Instant::now() + Duration::from_secs(10);
        while Path::new(&format!("/proc/{pid}")).exists() {
            assert!(Instant::now() < deadline, "process {pid} is still there");
            tokio::time::sleep(Duration::from_millis(20)).await;
        }
    }

    /// Runs a source that ends, and returns its result and what it sent.
    async fn outcome(fake: &Fake) -> (Result<(), Unavailable>, Vec<String>) {
        let queue = Arc::new(StreamQueue::new("a".repeat(32), agent_bucket()));
        let mut sink = LineSink::new(queue.clone(), 10);
        let result = tokio::time::timeout(
            Duration::from_secs(10),
            run_with(&fake.program(), UNIT, &mut sink),
        )
        .await
        .expect("the source ends");
        (result, sent(&queue.flush("c", Instant::now()).unwrap()))
    }

    async fn cut_all(input: &[u8]) -> Vec<String> {
        let mut reader = LineReader(input);
        let mut cut = EntryCut::default();
        let mut out = Vec::new();
        while let Some(line) = reader.next().await.unwrap() {
            assert!(line.kept.len() <= MAX_LINE_READ, "{}", line.kept.len());
            cut.push(&line, &mut out);
        }
        out
    }

    fn fixture(text: &str) -> Vec<String> {
        text.lines().map(str::to_owned).collect()
    }

    /// The fixtures hold the lines the earlier one-shot read over SSH printed for the same input.
    #[tokio::test]
    async fn cuts_entries_as_the_one_shot_read_does() {
        // A 400 KB message of 400 lines, between two ordinary entries.
        let prefix = "2026-09-25T19:05:31+00:00 beast lvtbig[2773152]: ";
        let indent = " ".repeat(prefix.len());
        let older = "2026-09-25T19:05:30+00:00 beast lvtbig[2773152]: older";
        let newer = "2026-09-25T19:05:32+00:00 beast lvtbig[2773152]: newer";
        let mut input = format!("{older}\n");
        for i in 0..400 {
            let lead = if i == 0 { prefix } else { &indent };
            input.push_str(&format!("{lead}part {i:03} {}\n", "q".repeat(1000)));
        }
        input.push_str(&format!("{newer}\n"));
        let cut = fixture(include_str!("../../tests/journalctl_cut_message.txt"));
        assert_eq!(cut.len(), 248);
        assert_eq!(cut.last().unwrap().trim_start(), MESSAGE_CUT);
        let expected: Vec<String> = [vec![older.to_owned()], cut, vec![newer.to_owned()]].concat();
        assert_eq!(cut_all(input.as_bytes()).await, expected);

        // A message of one 300 KB line. Its cut falls inside a two-byte character, so the line ends
        // one byte earlier.
        let line = format!(
            "2026-09-25T10:15:02+00:00 beast lvtbig[7]: a{}\n",
            "\u{e9}".repeat(150_000)
        );
        let expected = fixture(include_str!("../../tests/journalctl_cut_line.txt"));
        assert_eq!(cut_all(line.as_bytes()).await, expected);

        // A 40 KB message of 40 lines, as journalctl printed it, stays whole.
        let whole = include_str!("../../tests/journalctl_large_message.txt");
        assert_eq!(cut_all(whole.as_bytes()).await, fixture(whole));

        // A further line that would pass 256 KiB is not sent; the marker lines up with the message
        // column, one column per character.
        let prefix = "2026-09-25T10:15:02+00:00 b\u{eb}ast queue[7]: ";
        let input = format!(
            "{prefix}a\n{indent}{long}\n2026-09-25T10:15:03+00:00 b\u{eb}ast queue[7]: next\n",
            indent = " ".repeat(prefix.chars().count()),
            long = "y".repeat(300_000)
        );
        assert_eq!(
            cut_all(input.as_bytes()).await,
            [
                format!("{prefix}a"),
                format!("{}{MESSAGE_CUT}", " ".repeat(prefix.chars().count())),
                "2026-09-25T10:15:03+00:00 b\u{eb}ast queue[7]: next".to_owned(),
            ]
        );
    }

    /// The one-shot read stops after its byte limit, keeps the entry cut, and writes newest first.
    #[test]
    fn the_one_shot_read_stops_at_its_byte_limit_and_cuts_huge_entries() {
        let newest = "2026-09-25T10:00:03+00:00 host web[7]: newest";
        let older = format!(
            "2026-09-25T10:00:02+00:00 host web[7]: {}\n",
            "0".repeat(100)
        );
        let endless = format!("{newest}\n{}", older.repeat(50_000));
        let limit = 4 * 1024 * 1024;
        let output = newest_cut(endless.as_bytes(), limit).unwrap();
        assert_eq!(output.len(), limit);
        assert!(output.starts_with(format!("{newest}\n{older}").as_bytes()));

        // A message of a million short lines, between two ordinary entries.
        let indent = " ".repeat(39);
        let mut huge = "2026-09-25T10:00:03+00:00 host web[7]: newer\n".to_owned();
        huge.push_str("2026-09-25T10:00:02+00:00 host web[7]: huge 0\n");
        for i in 1..1_000_000 {
            huge.push_str(&format!("{indent}{i}\n"));
        }
        huge.push_str("2026-09-25T10:00:01+00:00 host web[7]: older\n");
        let output = String::from_utf8(newest_cut(huge.as_bytes(), limit).unwrap()).unwrap();
        let lines: Vec<&str> = output.lines().collect();
        assert!(output.len() < 270_000);
        assert_eq!(lines[2], format!("{indent}1"));
        assert_eq!(lines[lines.len() - 2], format!("{indent}{MESSAGE_CUT}"));
        assert_eq!(
            lines[lines.len() - 1],
            "2026-09-25T10:00:01+00:00 host web[7]: older"
        );
    }

    /// A 4 MiB message of a million short lines once made two million strings and ran the agent out
    /// of memory. journalctl prints it as about 60 MB of lines; the agent keeps 256 KiB of them.
    #[tokio::test]
    async fn a_4_mib_message_is_cut_at_256_kib_with_bounded_memory() {
        let prefix = "2026-09-25T10:15:02+00:00 orbit-e2e-app-dev big[7]: ";
        let indent = " ".repeat(prefix.len());
        let after = "2026-09-25T10:15:03+00:00 orbit-e2e-app-dev big[7]: after";
        let mut input = format!("{prefix}0\n");
        for i in 1..1_000_000 {
            input.push_str(&format!("{indent}{i}\n"));
        }
        input.push_str(&format!("{after}\n"));
        let out = cut_all(input.as_bytes()).await;
        assert_eq!(out[0], format!("{prefix}0"));
        assert_eq!(out[1], format!("{indent}1"));
        assert_eq!(out[out.len() - 2], format!("{indent}{MESSAGE_CUT}"));
        assert_eq!(out[out.len() - 1], after);
        let kept: usize = out[..out.len() - 2].iter().map(String::len).sum();
        assert!(kept <= MAX_ENTRY_TEXT_BYTES, "{kept}");
        assert!(kept > MAX_ENTRY_TEXT_BYTES - 100, "{kept}");

        // The same 4 MiB as one line: the agent keeps 512 KiB of it and sends 256 KiB of the message.
        let input = format!("{prefix}{}\n{after}\n", "x".repeat(4 << 20));
        let out = cut_all(input.as_bytes()).await;
        assert_eq!(
            out,
            [
                format!("{prefix}{}", "x".repeat(MAX_ENTRY_TEXT_BYTES)),
                format!("{indent}{MESSAGE_CUT}"),
                after.to_owned(),
            ]
        );
    }

    /// The first lines stop at the cut of a huge newest entry, and journalctl is stopped there instead
    /// of printing the rest.
    #[tokio::test]
    async fn a_4_mib_newest_entry_gives_256_kib_of_first_lines() {
        let fake = Fake::new(
            r#"echo '{"__CURSOR":"c1"}'"#,
            r#"echo $$ > "$D/history.pid"
awk 'BEGIN { print "2026-09-25T10:15:02+00:00 beast big[7]: 0"; for (i = 1; i < 1000000; i++) printf "%40s%d\n", "", i }'
exec sleep 600
"#,
            "exec sleep 600",
        );
        let stream = stream(&fake, 1000);
        let pid = fake.pid("history.pid").await;
        let first = lines(&stream.queue, 1).await;
        assert_eq!(
            first.last().unwrap(),
            &format!("{}{MESSAGE_CUT}", " ".repeat(40))
        );
        let bytes: usize = first.iter().map(String::len).sum();
        assert!(bytes <= FIRST_LINES_BYTES, "{bytes}");
        assert_gone(pid).await;
        assert!(fake.calls_after(3).await[2].contains(" --follow --after-cursor=c1 "));
        stream.task.abort();
    }

    /// The first lines end at the newest entry and the follow starts after it, so an entry written while
    /// the first lines are read arrives once. A follow from the journal's end would lose it.
    #[tokio::test]
    async fn hands_over_from_the_first_lines_to_the_follow_without_a_race() {
        let fake = Fake::new(
            r#"tail -n 1 "$D/journal" | cut -f 1 | sed 's/.*/{"__CURSOR":"&","__SEQNUM":"3"}/'"#,
            r#"awk -F '\t' -v c="$cursor" '{ print $2 } $1 == c { exit }' "$D/journal" | tac
printf 'c4\t%s\n' "2026-09-25T10:15:04+00:00 beast queue[4242]: written during the handoff" >> "$D/journal"
"#,
            FOLLOW_JOURNAL,
        );
        write_journal(
            &fake,
            &[
                ("c1", entry(1, "one")),
                ("c2", entry(2, "two")),
                ("c3", entry(3, "three")),
            ],
        );
        let stream = stream(&fake, 10);
        assert_eq!(
            lines(&stream.queue, 4).await,
            [
                entry(1, "one"),
                entry(2, "two"),
                entry(3, "three"),
                entry(4, "written during the handoff"),
            ]
        );
        quiet(&stream, &fake).await;
        let options = "--no-pager --quiet --unit=orbit-process-41-queue.service";
        assert_eq!(
            fake.calls(),
            [
                format!("{options} --lines=1 --output=json --output-fields=__CURSOR"),
                format!("{options} --reverse --lines=10 --cursor=c3 --output=short-iso --utc"),
                format!("{options} --follow --after-cursor=c3 --output=short-iso --utc"),
            ]
        );
        stream.task.abort();
    }

    /// Without an entry there is no cursor: the follow starts at the unit's first entry, so an entry
    /// written right after the cursor query still arrives once.
    #[tokio::test]
    async fn a_unit_without_entries_is_followed_from_its_first_entry() {
        let fake = Fake::new(
            r#"printf 'c1\t%s\n' "2026-09-25T10:15:01+00:00 beast queue[4242]: first" >> "$D/journal""#,
            "exit 7",
            FOLLOW_JOURNAL,
        );
        write_journal(&fake, &[]);
        let stream = stream(&fake, 10);
        assert_eq!(lines(&stream.queue, 1).await, [entry(1, "first")]);
        quiet(&stream, &fake).await;
        let calls = fake.calls();
        assert_eq!(calls.len(), 2, "no first lines are read: {calls:?}");
        assert!(calls[1].ends_with(" --follow --lines=all --output=short-iso --utc"));
        stream.task.abort();
    }

    /// A unit that does not exist has no entries: the stream stays open and sends nothing.
    #[tokio::test]
    async fn a_missing_unit_sends_nothing_and_keeps_following() {
        let fake = Fake::new(":", "exit 7", "exec sleep 600");
        let stream = stream(&fake, 10);
        quiet(&stream, &fake).await;
        quiet(&stream, &fake).await;
        assert!(fake.calls_after(2).await[1].contains(" --follow --lines=all "));
        stream.task.abort();
    }

    /// The controller aborts the reader of a stream it stops, for example when the viewer is gone.
    #[tokio::test]
    async fn stopping_a_stream_kills_its_journalctl() {
        let fake = Fake::new(
            ":",
            "exit 7",
            r#"echo $$ > "$D/follow.pid"; exec sleep 600"#,
        );
        let stream = stream(&fake, 10);
        let pid = fake.pid("follow.pid").await;
        stream.task.abort();
        assert!(stream.task.await.unwrap_err().is_cancelled());
        assert_gone(pid).await;
    }

    #[tokio::test]
    async fn full_first_lines_stop_their_journalctl() {
        let fake = Fake::new(
            r#"echo '{"__CURSOR":"c1"}'"#,
            r#"echo $$ > "$D/history.pid"
i=0
while :; do i=$((i + 1)); echo "2026-09-25T10:15:02+00:00 beast queue[4242]: entry $i"; done
"#,
            "exec sleep 600",
        );
        let stream = stream(&fake, 3);
        let pid = fake.pid("history.pid").await;
        assert_eq!(
            lines(&stream.queue, 3).await,
            [
                entry(2, "entry 3"),
                entry(2, "entry 2"),
                entry(2, "entry 1")
            ]
        );
        assert_gone(pid).await;
        stream.task.abort();
    }

    #[tokio::test]
    async fn journalctl_that_is_missing_fails_or_ends_ends_the_stream() {
        let queue = Arc::new(StreamQueue::new("a".repeat(32), agent_bucket()));
        let mut sink = LineSink::new(queue, 10);
        assert_eq!(
            run_with(Path::new("/nonexistent/journalctl"), UNIT, &mut sink).await,
            Err(Unavailable("journalctl"))
        );

        // The follow ends after the lines it printed.
        let fake = Fake::new(
            ":",
            "exit 7",
            r#"echo "2026-09-25T10:15:09+00:00 beast queue[4242]: last""#,
        );
        let (result, sent) = outcome(&fake).await;
        assert_eq!(result, Err(Unavailable("journalctl output")));
        assert_eq!(sent, [entry(9, "last")]);

        for (cursor, history, error) in [
            ("exit 1", "", "journalctl output"),
            (r#"echo '{"__CURSOR":"c1"}'"#, "exit 1", "journalctl output"),
            ("echo 'not json'", "", "journal cursor"),
            (r#"echo '{"__CURSOR":"two words"}'"#, "", "journal cursor"),
            (r#"echo '{"__SEQNUM":"1"}'"#, "", "journal cursor"),
            (
                "head -c 10000 /dev/zero | tr '\\0' x",
                "",
                "journalctl output",
            ),
        ] {
            let fake = Fake::new(cursor, history, "exec sleep 600");
            assert_eq!(outcome(&fake).await.0, Err(Unavailable(error)), "{cursor}");
        }
    }

    #[tokio::test]
    async fn first_lines_hold_at_most_256_kib_of_the_newest_entries() {
        let fake = Fake::new(
            "",
            r#"awk 'BEGIN { for (j = 0; j < 20000; j++) x = x "x"; for (i = 99; i >= 0; i--) printf "2026-09-25T10:15:02+00:00 beast queue[4242]: %03d %s\n", i, x }'"#,
            "",
        );
        let lines = first_lines(&fake.program(), UNIT, "c1", 1000)
            .await
            .unwrap();
        let bytes: usize = lines.iter().map(String::len).sum();
        assert!(bytes <= FIRST_LINES_BYTES + 21_000, "{bytes}");
        assert!(lines.len() < 20, "{}", lines.len());
        assert!(lines.last().unwrap().contains("queue[4242]: 099 "));

        // Entries come newest first; each keeps its own lines in order.
        let indent = " ".repeat(entry(0, "").len());
        let fake = Fake::new(
            "",
            &format!(
                "echo '{}'\necho '{indent}second line'\necho '{}'",
                entry(2, "two"),
                entry(1, "one")
            ),
            "",
        );
        assert_eq!(
            first_lines(&fake.program(), UNIT, "c2", 10).await.unwrap(),
            [
                entry(1, "one"),
                entry(2, "two"),
                format!("{indent}second line")
            ]
        );
    }

    #[tokio::test]
    async fn stream_end_to_end_redacts_and_cuts_long_lines() {
        let fake = Fake::new(
            r#"echo '{"__CURSOR":"c2"}'"#,
            r#"echo "2026-09-25T10:15:02+00:00 queue[7]: token=abc"
echo "2026-09-25T10:15:02+00:00 queue[7]: one"
"#,
            r#"echo "2026-09-25T10:15:03+00:00 queue[7]: three"
echo "2026-09-25T10:15:04+00:00 queue[7]: $(head -c 10000 /dev/zero | tr '\0' z)"
exec sleep 600
"#,
        );
        let stream = stream(&fake, 10);
        let sent = lines(&stream.queue, 4).await;
        assert_eq!(
            sent[..3],
            [
                "2026-09-25T10:15:02+00:00 queue[7]: one",
                "2026-09-25T10:15:02+00:00 queue[7]: token=[REDACTED]",
                "2026-09-25T10:15:03+00:00 queue[7]: three",
            ]
        );
        assert_eq!(sent[3].len(), MAX_LINE_BYTES);
        assert!(sent[3].ends_with(" [truncated]"));
        stream.task.abort();
    }

    /// Runs the agent's arguments against this machine's journalctl, when it can read a journal: a
    /// unit without entries is followed, not refused.
    #[tokio::test]
    async fn real_journalctl_accepts_the_arguments() {
        let readable = std::process::Command::new(JOURNALCTL)
            .args(["--quiet", "--lines=0"])
            .output()
            .is_ok_and(|output| output.status.success());
        if !readable {
            eprintln!("skipped: {JOURNALCTL} cannot read a journal here");
            return;
        }
        let queue = Arc::new(StreamQueue::new("a".repeat(32), agent_bucket()));
        let mut sink = LineSink::new(queue.clone(), 10);
        let task = tokio::spawn(async move {
            run("orbit-process-999999-none.service".into(), &mut sink).await
        });
        let stream = Stream { queue, task };
        // Keep the real observation intervals: a real journal has no fixture barrier.
        for _ in 0..2 {
            tokio::time::sleep(Duration::from_millis(700)).await;
            let flush = stream.queue.flush("c", Instant::now()).unwrap();
            assert!(flush.frames.is_empty(), "{:?}", flush.frames);
            assert!(!stream.task.is_finished());
        }
        stream.task.abort();
    }

    /// Follows a real unit on this machine. Run on a Linux host with
    /// `ORBIT_JOURNAL_UNIT=ssh.service cargo test real_journal -- --ignored --nocapture`.
    #[tokio::test]
    #[ignore]
    async fn real_journal() {
        let unit = std::env::var("ORBIT_JOURNAL_UNIT").unwrap_or_else(|_| "ssh.service".into());
        let seconds: u64 = std::env::var("ORBIT_JOURNAL_FOLLOW_SECONDS")
            .ok()
            .and_then(|n| n.parse().ok())
            .unwrap_or(2);
        let queue = Arc::new(StreamQueue::new("a".repeat(32), agent_bucket()));
        let mut sink = LineSink::new(queue.clone(), 20);
        let task = tokio::spawn(async move { run(unit, &mut sink).await });
        for _ in 0..seconds * 2 {
            tokio::time::sleep(Duration::from_millis(500)).await;
            for line in sent(&queue.flush("c", Instant::now()).unwrap()) {
                println!("{line}");
            }
        }
        assert!(!task.is_finished(), "{:?}", task.await);
        task.abort();
    }
}
