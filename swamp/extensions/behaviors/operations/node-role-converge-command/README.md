# Native role convergence

`node.role_converge` runs `operation-node-role-add` with an existing assignment
and complete intent captured at admission. Official resources apply and check
the role. Native assertions finish the run. Matching Reverb services and Metrics
containers keep their runtime identities; Node convergence owns the observer
and CLI. The retained-role dispatcher and its add-direct workflow are removed.

The tests here cover shared observer asset rendering. The role resource graph
has native coverage in `swamp/tests/node_role_add_native_test.py` and real proof
receipts in `swamp/proof/receipts/node-role-converge.json`.
