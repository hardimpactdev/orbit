"""MySQL clone exercises the same canonical native HTTP/privacy/recovery harness."""
import unittest
from create_native_test import CreateNative

class MysqlCloneNative(CreateNative):
    kind = 'database.mysql_clone'
    workflow = 'operation-database-mysql-clone'
    module = 'mysql_clone'
    factory = 'mysqlClone'

    def setUp(self):
        super().setUp()
        self.operation['input'].update(driver='mysql', source_database='proof_source')

if __name__ == '__main__':
    unittest.main(defaultTest='MysqlCloneNative')
