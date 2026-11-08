/** Reconstructed from the supplied try-10 before-state, proof 12624; not a remote artifact copy. */
export const partialApplication = `Status: active

     To                         Action      From
     --                         ------      ----
[ 1] 47865/tcp                  ALLOW IN    10.232.2.11               # orbit
[ 2] 47510/tcp                  ALLOW IN    10.232.2.11               # orbit
[ 3] 10.45.0.2 on orbit         ALLOW IN    Anywhere                  # orbit:wireguard-members
[ 4] 47865/tcp                  DENY IN     Anywhere                  # proof:deny
[ 5] 47510/tcp                  DENY IN     Anywhere                  # proof:deny
[ 6] 47372/tcp                  ALLOW IN    10.232.2.11               # proof:existing
[ 7] 47372/tcp                  DENY IN     Anywhere                  # proof:deny
[ 8] 47372/udp                  DENY IN     Anywhere                  # proof:deny
[ 9] Anywhere                   ALLOW IN    10.232.2.0/24             # proof-network
[10] 47865/tcp (v6)             DENY IN     Anywhere (v6)             # proof:deny
[11] 47510/tcp (v6)             DENY IN     Anywhere (v6)             # proof:deny
[12] 47372/tcp (v6)             DENY IN     Anywhere (v6)             # proof:deny
[13] 47372/udp (v6)             DENY IN     Anywhere (v6)             # proof:deny
`;
