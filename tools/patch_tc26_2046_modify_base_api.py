from pathlib import Path

p = Path('pallets-shipping-android/app/src/main/java/com/smproduce/palletsshipping/Tc26MainActivity.java')
s = p.read_text()

s = s.replace('callUrl(BuildConfig.MODIFY_API_URL,map("action","verify_password","password",pin),j->{',
              'call(map("action","verify_skip_po_password","password",pin),j->{')
s = s.replace('callUrl(BuildConfig.MODIFY_API_URL,map("action","edit_open","pallet_id",id,"password",modifyPassword),j->{',
              'call(map("action","pallet_edit_detail","pallet_id",id,"password",modifyPassword),j->{')
s = s.replace('callUrl(BuildConfig.MODIFY_API_URL,map("action","edit_case","pallet_id",palletId,"case_serial",code,"mode",mode,"password",modifyPassword),j->{',
              'call(map("action","pallet_edit_case","pallet_id",palletId,"case_serial",code,"mode",mode,"password",modifyPassword),j->{')
s = s.replace('callUrl(BuildConfig.MODIFY_API_URL,map("action","lookup_case","case_serial",code,"password",modifyPassword),j->{',
              'call(map("action","pallet_lookup_case","case_serial",code,"password",modifyPassword),j->{')
s = s.replace('callUrl(BuildConfig.MODIFY_API_URL,map("action","printers"),j->{',
              'call(map("action","pallet_printers"),j->{')
s = s.replace('private void saveOpen(){callUrl(BuildConfig.MODIFY_API_URL,map("action","set_status","pallet_id",palletId,"status","OPEN","print_label","0","password",modifyPassword),j->done("Pallet "+palletId+" → OPEN"));}',
              'private void saveOpen(){call(map("action","pallet_set_open","pallet_id",palletId,"password",modifyPassword),j->done("Pallet "+palletId+" → OPEN"));}')
old = '''        callUrl(BuildConfig.MODIFY_API_URL,map("action","set_status","pallet_id",palletId,"status",status,"print_label","0","password",modifyPassword),j->{if(print)printExisting(status,printerId);else done("Pallet "+palletId+" → "+status+tr(" · saved without printing"," · guardado sin imprimir"));});'''
new = '''        call(map("action","pallet_partial_no_print","pallet_id",palletId,"password",modifyPassword),j->{if(print)printExisting(status,printerId);else done("Pallet "+palletId+" → "+status+tr(" · saved without printing"," · guardado sin imprimir"));});'''
if old not in s:
    raise SystemExit('partial save block not found')
s = s.replace(old,new,1)
s = s.replace('callUrl(BuildConfig.MODIFY_API_URL,map("action","print_label","pallet_id",palletId,"status",status,"printer_id",String.valueOf(printerId),"password",modifyPassword),j->',
              'call(map("action","pallet_print_label","pallet_id",palletId,"status",status,"printer_id",String.valueOf(printerId),"password",modifyPassword),j->')
s = s.replace('callUrl(BuildConfig.MODIFY_API_URL,map("action","delete_pallet","pallet_id",palletId,"password",pin),j->{',
              'call(map("action","pallet_delete_full","pallet_id",palletId,"password",pin),j->{')

if 'BuildConfig.MODIFY_API_URL' in s:
    raise SystemExit('MODIFY_API_URL remains in cumulative Tc26MainActivity')
for required in ['pallet_edit_detail','pallet_edit_case','pallet_lookup_case','pallet_printers','pallet_set_open','pallet_partial_no_print','pallet_print_label','pallet_delete_full']:
    if required not in s:
        raise SystemExit(required+' missing after patch')
p.write_text(s)
