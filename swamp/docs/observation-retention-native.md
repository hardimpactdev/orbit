# Native observation retention

The retention selector receives public record metadata captured at admission as workflow intent. The projector supplies the shared intent and execution_secrets envelope and never refreshes record lists during execution. Swamp selects expired batch and failed-job IDs and prints one public JSON object for the shared terminal projector. Retention needs no vault values, Node proxy, agent command or Gateway request from a step. The Gateway keeps record deletion and its public counts.

The expiry rules keep strict whole-second boundaries. Finished batches use finished_at; unfinished batches use created_at; cancelled batches also use created_at. Disabled Tasks suppress batch pruning and retain failed-job pruning. Retention never selects Activities. A malformed timestamp refuses the selection before a result is emitted.

The standalone selector is a draft component of observation.maintain. It has not replaced the scheduled entry point. The shared scheduling owner must supply fresh intent and a distinct Operation for each firing without the old admission callback. A fixed schedule input cannot provide a fresh record inventory on future days. The existing daily cadence remains 02:17:10 UTC. No family polling or schedule relay replaces the missing shared contract.
