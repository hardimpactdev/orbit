"""Native outcome/recovery/privacy contract, plus separately run real Valkey effects."""
import unittest
import user_create_native_test as base


class ServerCloseNative(base.UserCreateNative):
    def setUp(self):
        super().setUp()
        failure = self.repo / 'extensions/behaviors/operations/failure.ts'
        failure.parent.mkdir(parents=True, exist_ok=True)
        failure.write_text('import { fixtureHttp } from "./database/server_close_native_fixture.ts";\n' + (ROOT / 'extensions/behaviors/operations/failure.ts').read_text().replace('export const behavior = nativeFailure();', 'export const behavior = nativeFailure(() => fixtureHttp);'))
        module = self.repo / 'extensions/behaviors/operations/database/server_close.ts'
        module.write_text('import { fixtureHttp } from "./user_create_native_fixture.ts";\n'
                          'import { fixtureConnect } from "./server_close_native_fixture.ts";\n'
                          + module.read_text().replace('...serverClose()', '...serverClose(() => fixtureHttp, fixtureConnect)'))
        old = self.repo / 'workflows/workflow-operation-database-user-create.yaml'
        old.unlink()
        workflow = base.ROOT / 'workflows/workflow-operation-database-server-close.yaml'
        fixture_workflow = yaml.safe_load(workflow.read_text())
        fixture_workflow.pop('target', None)
        fixture_workflow.pop('labels', None)
        for job in fixture_workflow['jobs']:
            job.pop('target', None)
            job.pop('labels', None)
            for step in job['steps']:
                step.pop('target', None)
                step.pop('labels', None)
        (self.repo / 'workflows' / workflow.name).write_text(yaml.safe_dump(fixture_workflow, sort_keys=False))
        self.fixture['operation']['kind'] = 'database_server.close'
        self.fixture['operation']['input'] = {'host': '10.44.0.209', 'port': 6379, 'default_open': True}
        self.fixture['material'] = {'password': self.password}

    def cli(self, *args, **kwargs):
        return super().cli(*(arg.replace('operation-database-user-create', 'operation-database-server-close')
                             .replace('kind=database_user.create', 'kind=database_server.close')
                             for arg in args), **kwargs)


if __name__ == '__main__':
    unittest.main()
