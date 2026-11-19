from pathlib import Path
import subprocess

base=Path(__file__).resolve().parent
repo=base/'local_repository'
repo.mkdir(exist_ok=True)
logs=base/'transcripts'
logs.mkdir(exist_ok=True)
lines=[]
def run(*args):
    lines.append('$ '+' '.join(args))
    p=subprocess.run(args,cwd=repo,text=True,stdout=subprocess.PIPE,stderr=subprocess.STDOUT)
    lines.append(p.stdout.rstrip())
    if p.returncode: raise RuntimeError(p.stdout)
def save(n):
    (logs/f'{n}.txt').write_text('\n'.join(lines)+'\n');lines.clear()
run('git','init','-b','main')
run('git','config','user.name','Денис')
run('git','config','user.email','denis@example.invalid')
(repo/'README.md').write_text('# GitFlow клиники Здоровье+\nЛокальный учебный пример.\n')
run('git','add','README.md');run('git','commit','-m','init: clinic GitFlow example')
run('git','switch','-c','develop');run('git','switch','-c','feature/appointments')
run('git','branch','-a');save('01_init')
(repo/'appointment_rules.txt').write_text('Пациент и врач должны быть доступны.\nПересекающиеся приёмы запрещены.\n')
run('git','add','.');run('git','commit','-m','feat: appointment scheduling rules')
run('git','switch','develop');run('git','merge','--no-ff','feature/appointments','-m','merge: appointments into develop');save('02_feature')
run('git','switch','-c','release/1.0.0')
(repo/'VERSION').write_text('1.0.0\n');run('git','add','VERSION');run('git','commit','-m','release: 1.0.0')
run('git','switch','main');run('git','merge','--no-ff','release/1.0.0','-m','merge: release 1.0.0')
run('git','tag','-a','v1.0.0','-m','Clinic 1.0.0');run('git','switch','develop');run('git','merge','--no-ff','release/1.0.0','-m','merge: release back into develop');save('03_release')
run('git','switch','main');run('git','switch','-c','hotfix/1.0.1')
with (repo/'appointment_rules.txt').open('a') as f:f.write('Отмена сохраняет историю и освобождает интервал.\n')
(repo/'VERSION').write_text('1.0.1\n');run('git','add','.');run('git','commit','-m','fix: preserve cancellation history')
run('git','switch','main');run('git','merge','--no-ff','hotfix/1.0.1','-m','merge: hotfix 1.0.1')
run('git','tag','-a','v1.0.1','-m','Clinic 1.0.1');run('git','switch','develop');run('git','merge','--no-ff','hotfix/1.0.1','-m','merge: hotfix back into develop');save('04_hotfix')
run('git','log','--all','--graph','--oneline','--decorate');run('git','tag','-n');run('git','status','--short','--branch');save('05_history')
print('GitFlow complete:',repo)
