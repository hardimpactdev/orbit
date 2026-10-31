"""Native outcome/recovery/privacy contract, plus separately run real Valkey effects."""
import unittest
import user_create_native_test as base


class ServerCloseNative(base.UserCreateNative):
    def setUp(self):
        super().setUp()
        module = self.repo / 'extensions/behaviors/operations/database/server_close.ts'
        module.write_text('import { fixtureHttp } from "./user_create_native_fixture.ts";\n'
                          'import { fixtureConnect } from "./server_close_native_fixture.ts";\n'
                          + module.read_text().replace('...serverClose()', '...serverClose(() => fixtureHttp, fixtureConnect)'))
        old = self.repo / 'workflows/workflow-operation-database-user-create.yaml'
        old.unlink()
        workflow = base.ROOT / 'workflows/workflow-operation-database-server-close.yaml'
        (self.repo / 'workflows' / workflow.name).write_text('\n'.join(
            line for line in workflow.read_text().splitlines()
            if not line.startswith(('target:', 'labels:'))) + '\n')
        self.fixture['operation']['kind'] = 'database_server.close'
        self.fixture['operation']['input'] = {'host': '10.44.0.209', 'port': 6379, 'default_open': True}
        self.fixture['material'] = {'password': self.password}

    def cli(self, *args, **kwargs):
        return super().cli(*(arg.replace('operation-database-user-create', 'operation-database-server-close')
                             .replace('kind=database_user.create', 'kind=database_server.close')
                             for arg in args), **kwargs)


if __name__ == '__main__':
    unittest.main()
