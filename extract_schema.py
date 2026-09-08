import sys
with open('my_project_db.sql', 'r', encoding='utf-8') as f:
    with open('schema_clean.txt', 'w', encoding='utf-8') as out:
        for line in f:
            if line.startswith('CREATE TABLE') or line.startswith('ALTER TABLE') or line.startswith('  `') or line.startswith(') ENGINE') or line.startswith('  ADD CONSTRAINT') or line.startswith('  PRIMARY KEY') or line.startswith('  KEY '):
                out.write(line)
