//! ACL passage shared with the Vite+ store. Route workload publication lives in Swamp.

/// Adds passage to one account's entry and the mask without changing their read or write bits.
pub(super) fn passage_grant(acl: &str, user: &str) -> String {
    let field = |prefix: &str| {
        acl.lines()
            .find_map(|line| line.strip_prefix(prefix))
            .map(|rest| rest.chars().take(3).collect::<String>())
    };
    let permissions = field(&format!("user:{user}:")).unwrap_or_else(|| "---".into());
    let mask = field("mask::")
        .or_else(|| field("group::"))
        .unwrap_or_else(|| "---".into());
    format!("u:{user}:{}x,m::{}x", &permissions[..2], &mask[..2])
}

#[cfg(test)]
mod tests {
    use super::*;

    #[test]
    fn a_passage_grant_adds_execute_to_caddy_and_the_mask_only() {
        assert_eq!(
            passage_grant(
                "user::rwx\nuser:caddy:r--\ngroup::r-x\nmask::r--\nother::---\n",
                "caddy"
            ),
            "u:caddy:r-x,m::r-x"
        );
        assert_eq!(
            passage_grant("user::rwx\ngroup::rw-\nother::---\n", "caddy"),
            "u:caddy:--x,m::rwx"
        );
    }
}
