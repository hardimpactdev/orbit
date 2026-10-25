//! Strict Grok gRPC-web-text billing decoder. An absent percentage proves zero only in an active period.
use crate::quota::iso;
use base64::{engine::general_purpose::STANDARD, Engine};
use serde_json::{json, Value};
use std::collections::{BTreeMap, BTreeSet};

const INVALID: &str = "Invalid Grok billing response";
#[derive(Default)]
struct Fields {
    integers: BTreeMap<Vec<u64>, u64>,
    floats: BTreeMap<Vec<u64>, f32>,
}
fn varint(data: &[u8], index: &mut usize) -> Result<u64, &'static str> {
    let mut value = 0;
    for shift in (0..70).step_by(7) {
        let byte = *data.get(*index).ok_or(INVALID)?;
        *index += 1;
        if shift == 63 && byte > 1 {
            return Err(INVALID);
        }
        value |= u64::from(byte & 127) << shift;
        if byte < 128 {
            return Ok(value);
        }
    }
    Err(INVALID)
}
fn scan(data: &[u8], path: &[u64], fields: &mut Fields) -> Result<(), &'static str> {
    let mut index = 0;
    let mut seen = BTreeSet::new();
    while index < data.len() {
        let key = varint(data, &mut index)?;
        let (field, wire) = (key >> 3, key & 7);
        let mut current = path.to_vec();
        current.push(field);
        if !(1..=536870911).contains(&field)
            || !seen.insert(field)
            || (current == [1, 1] && wire != 5)
        {
            return Err(INVALID);
        }
        if wire == 0 {
            fields.integers.insert(current, varint(data, &mut index)?);
            continue;
        }
        let length = match wire {
            1 => 8,
            2 => usize::try_from(varint(data, &mut index)?).map_err(|_| INVALID)?,
            5 => 4,
            _ => return Err(INVALID),
        };
        let end = index
            .checked_add(length)
            .filter(|end| *end <= data.len())
            .ok_or(INVALID)?;
        let value = &data[index..end];
        index = end;
        let nested = matches!(
            current.as_slice(),
            [1] | [1, 2]
                | [1, 3]
                | [1, 4]
                | [1, 5]
                | [1, 6]
                | [1, 7]
                | [1, 8]
                | [1, 12]
                | [1, 6, 1]
                | [1, 6, 2]
                | [1, 6, 3]
                | [1, 8, 2]
                | [1, 8, 3]
                | [1, 6, 3, 2]
                | [1, 6, 3, 3]
        );
        if wire == 2 && nested {
            scan(value, &current, fields)?;
        } else if wire == 5 {
            fields.floats.insert(
                current,
                f32::from_le_bytes(value.try_into().map_err(|_| INVALID)?),
            );
        }
    }
    Ok(())
}
pub fn windows(body: &Value, now: f64) -> Result<Vec<Value>, &'static str> {
    let encoded: String = body
        .as_str()
        .ok_or(INVALID)?
        .chars()
        .filter(|c| !c.is_whitespace())
        .collect();
    let mut raw = Vec::new();
    let bytes = encoded.as_bytes();
    let mut start = 0;
    // Each independently encoded frame can end with padding inside the complete body.
    while start < bytes.len() {
        let mut end = start;
        while end < bytes.len() && bytes[end] != b'=' {
            end += 1;
        }
        while end < bytes.len() && bytes[end] == b'=' {
            end += 1;
        }
        raw.extend(STANDARD.decode(&bytes[start..end]).map_err(|_| INVALID)?);
        start = end;
    }
    let mut data = None;
    let mut status = None;
    let mut offset = 0;
    while offset < raw.len() {
        let header = raw.get(offset..offset + 5).ok_or(INVALID)?;
        let length = u32::from_be_bytes(header[1..].try_into().map_err(|_| INVALID)?) as usize;
        offset += 5;
        let end = offset
            .checked_add(length)
            .filter(|end| *end <= raw.len())
            .ok_or(INVALID)?;
        let payload = &raw[offset..end];
        offset = end;
        match header[0] {
            0 if data.is_none() => data = Some(payload),
            128 => {
                if !payload.is_ascii() {
                    return Err(INVALID);
                }
                for line in std::str::from_utf8(payload).map_err(|_| INVALID)?.lines() {
                    if let Some((key, value)) = line.split_once(':') {
                        if key.trim().eq_ignore_ascii_case("grpc-status") {
                            if status.is_some() {
                                return Err(INVALID);
                            }
                            status = Some(value.trim());
                        }
                    }
                }
            }
            _ => return Err(INVALID),
        }
    }
    if status != Some("0") {
        return Err(INVALID);
    }
    let mut fields = Fields::default();
    scan(data.ok_or(INVALID)?, &[], &mut fields)?;
    let integer = |path: &[u64]| fields.integers.get(path).copied();
    let period = integer(&[1, 8, 1]);
    let start = integer(&[1, 8, 2, 1]);
    let end = integer(&[1, 8, 3, 1]);
    let used = match fields.floats.get(&vec![1, 1]) {
        Some(used) => f64::from(*used),
        None if fields.floats.is_empty()
            && matches!(period, Some(1 | 2))
            && start.is_some_and(|s| s as f64 <= now)
            && end.is_some_and(|e| now < e as f64) =>
        {
            0.
        }
        None => return Err("Grok billing did not report a verifiable quota"),
    };
    if !used.is_finite() || !(0. ..=100.).contains(&used) {
        return Err(INVALID);
    }
    let reset = end
        .or_else(|| integer(&[1, 5, 1]))
        .filter(|end| *end as f64 > now)
        .ok_or("Grok billing period is missing or expired")?;
    let reset = iso(reset as f64).ok_or(INVALID)?;
    Ok(vec![
        json!({"label": match period { Some(2) => "7d", Some(1) => "Monthly credits", _ => "Credits" }, "used_percent": used, "remaining_percent": 100. - used, "resets_at": reset}),
    ])
}

#[cfg(test)]
pub(crate) mod tests {
    use super::*;
    fn varint(mut value: u64) -> Vec<u8> {
        let mut out = Vec::new();
        while value >= 128 {
            out.push(value as u8 | 128);
            value >>= 7;
        }
        out.push(value as u8);
        out
    }
    fn field(number: u64, value: &[u8]) -> Vec<u8> {
        [
            varint(number * 8 + 2),
            varint(value.len() as u64),
            value.to_vec(),
        ]
        .concat()
    }
    fn frame(flag: u8, body: &[u8]) -> String {
        STANDARD.encode(
            [
                vec![flag],
                (body.len() as u32).to_be_bytes().to_vec(),
                body.to_vec(),
            ]
            .concat(),
        )
    }
    pub(crate) fn fixture(
        percent: Option<f32>,
        start: u64,
        end: u64,
        status: &str,
        extra: &[u8],
        copies: usize,
    ) -> Value {
        let period = [
            vec![8, 2],
            field(2, &[vec![8], varint(start)].concat()),
            field(3, &[vec![8], varint(end)].concat()),
        ]
        .concat();
        let mut config = percent
            .map(|p| [vec![13], p.to_le_bytes().to_vec()].concat())
            .unwrap_or_default();
        config.extend(field(8, &period));
        config.extend(extra);
        json!(
            frame(0, &field(1, &config)).repeat(copies)
                + &frame(128, format!("grpc-status:{status}\r\n").as_bytes())
        )
    }
    #[test]
    fn reads_segmented_base64_and_proves_a_fresh_period_zero() {
        let body = fixture(Some(42.5), 1999999900, 2000000100, "0", &[], 1);
        assert_eq!(
            windows(&body, 2000000000.).unwrap()[0]["remaining_percent"],
            57.5
        );
        let body = fixture(None, 1999999900, 2000000100, "0", &[], 1);
        let w = windows(&body, 2000000000.).unwrap();
        assert_eq!(w[0]["used_percent"], 0.);
        assert_eq!(w[0]["label"], "7d");
    }
    #[test]
    fn refuses_unverifiable_zero_ambiguous_fields_malformed_frames_and_nonfinite_percent() {
        for body in [
            fixture(None, 2000000010, 2000000100, "0", &[], 1),
            fixture(None, 1999999900, 1999999999, "0", &[], 1),
            fixture(None, 1999999900, 2000000100, "7", &[], 1),
            fixture(Some(f32::NAN), 1999999900, 2000000100, "0", &[], 1),
            fixture(Some(101.), 1999999900, 2000000100, "0", &[], 1),
            fixture(None, 1999999900, 2000000100, "0", &[], 2),
            fixture(None, 1999999900, 2000000100, "0", &[10, 1, 0], 1),
            fixture(None, 1999999900, 2000000100, "0", &[8, 0], 1),
            fixture(Some(42.), 1999999900, 2000000100, "0", &[13, 0, 0, 0, 0], 1),
            json!(""),
            json!("!!!"),
            json!("AAAAAAE="),
            json!(frame(0, &[0xff; 11]) + &frame(128, b"grpc-status:0\r\n")),
            json!(frame(0, &field(1, &[])) + &frame(128, b"grpc-status:0\r\n")),
        ] {
            assert!(windows(&body, 2000000000.).is_err(), "{body}");
        }
    }
}
