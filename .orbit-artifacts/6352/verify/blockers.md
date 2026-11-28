# ORB-6352 blockers (base a77e9d95)

Today's route.create (`route.publish_site`, apps/agent/crates/route/src/site.rs) has steps that the PR A
primitives cannot express without a Rust change or a behaviour change. The brief forbids both.

1. Recursive Caddy access. RouteWebRootCommands::access (apps/gateway/app/Domain/Routes/RouteWebRootCommands.php:28-53)
   admits, for every Route with a checkout:
   - `find -P <checkout> -type d -exec setfacl -m d:u:caddy:--- {} +` (every folder below)
   - `setfacl -P -R -n -m u:caddy:--- <checkout>` (whole tree)
   - the same two over the served roots, granting `d:u:caddy:r-x` / `u:caddy:r-X`
   `posix.acl.ensure` acts on exactly one path, with nothing below it changing, and docs/reference/executor.md says
   "If a recursive ACL is genuinely needed, that is a primitive-rule change for Nick." The Gateway does not know the
   checkout's file tree, so it cannot send one order per path.

2. The account's copy of the leaf key. site.rs:345-361 runs `install -D -o <user> -g <gid> -m 0600` to copy
   `/etc/caddy/orbit-certificates/<scope>/versions/<v>/key.pem` (made on the Node by tls.ensure_csr; it never leaves
   the Node) into `<home>/.orbit/certificates/<scope>/versions/<v>/`. `file.ensure` takes content only as a blob from
   the Gateway, which never holds the key. No primitive copies a file on the host.

3. Smaller gap: the pool files carry `__ORBIT_NODE_GROUP__` and `/__ORBIT_NODE_HOME__`, which site.rs replaces with
   the account's gid and home read on the Node. NativeRouteCreatePlan.php:225 and :255 rely on the run to do this. To render
   the whole file, the Gateway needs both values in the admitted plan (a Gateway change, which is in scope).
