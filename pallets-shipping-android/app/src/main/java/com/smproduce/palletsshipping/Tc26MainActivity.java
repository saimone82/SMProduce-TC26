package com.smproduce.palletsshipping;

import android.app.AlertDialog;
import android.content.SharedPreferences;
import android.graphics.Color;
import android.text.InputType;
import android.view.Gravity;
import android.widget.Button;
import android.widget.EditText;
import android.widget.LinearLayout;
import android.widget.TextView;
import android.widget.Toast;

import org.json.JSONArray;
import org.json.JSONObject;

import java.util.ArrayList;
import java.util.List;
import java.util.Map;

public class Tc26MainActivity extends MainActivity {
    private static final int EDIT_LOOKUP = 0;
    private static final int EDIT_ADD = 1;
    private static final int EDIT_REMOVE = 2;

    private int editMode = EDIT_LOOKUP;
    private boolean editDirty = false;
    private String modifyPassword = "";
    private String loadedPalletStatus = "";

    private SharedPreferences modifyPrefs() {
        return getSharedPreferences("tc26_modify_pallet", MODE_PRIVATE);
    }

    @Override Map<String,String> map(String... values) {
        Map<String,String> params = super.map(values);
        params.put("app_version", "2.0.40-tc26");
        return params;
    }

    @Override void requestPalletEditPassword() {
        if (!online) {
            error(tr("Connect before modifying a pallet", "Conéctese antes de modificar un pallet"));
            return;
        }
        final EditText input = new EditText(this);
        input.setInputType(InputType.TYPE_CLASS_NUMBER | InputType.TYPE_NUMBER_VARIATION_PASSWORD);
        final AlertDialog dialog = new AlertDialog.Builder(this)
                .setTitle(tr("Modify Pallet", "Modificar pallet"))
                .setMessage(tr("Enter the protected PIN", "Introduzca el PIN protegido"))
                .setView(input)
                .setNegativeButton(tr("Cancel", "Cancelar"), null)
                .setPositiveButton(tr("Continue", "Continuar"), null)
                .create();
        dialog.setOnShowListener(x -> dialog.getButton(AlertDialog.BUTTON_POSITIVE).setOnClickListener(v -> {
            final String pin = input.getText().toString().trim();
            if (pin.isEmpty()) return;
            callUrl(BuildConfig.MODIFY_API_URL, map("action", "verify_password", "password", pin), j -> {
                modifyPassword = pin;
                palletEditPassword = pin;
                editMode = EDIT_LOOKUP;
                editDirty = false;
                dialog.dismiss();
                step = Step.PALLET_EDIT_ID;
                render();
            });
        }));
        dialog.show();
    }

    @Override void openPalletForEdit(String id) {
        if (!online) {
            error(tr("Connect to modify a pallet", "Conéctese para modificar un pallet"));
            return;
        }
        callUrl(BuildConfig.MODIFY_API_URL,
                map("action", "edit_open", "pallet_id", id, "password", modifyPassword), j -> {
                    palletId = j.optString("pallet_id");
                    caseCount = j.optInt("cases_count");
                    loadedPalletStatus = j.optString("status", "OPEN").toUpperCase();
                    loadCases(j);
                    editMode = EDIT_LOOKUP;
                    editDirty = false;
                    step = Step.PALLET_EDIT_CASES;
                    render();
                });
    }

    @Override void palletEditScreen() {
        heading(tr("Modify pallet", "Modificar pallet"), palletId);
        counter = tv(caseCount + " " + tr("CASES", "CAJAS"), 34, Color.rgb(102,187,106));
        counter.setGravity(Gravity.CENTER);
        counter.setTypeface(null, 1);
        body.addView(counter);

        final String modeText;
        final int modeColor;
        if (editMode == EDIT_ADD) {
            modeText = tr("ADD MODE — scan cases to add", "MODO AÑADIR — escanee cajas para añadir");
            modeColor = Color.rgb(134,239,172);
        } else if (editMode == EDIT_REMOVE) {
            modeText = tr("REMOVE MODE — scan cases to remove", "MODO ELIMINAR — escanee cajas para eliminar");
            modeColor = Color.rgb(248,113,113);
        } else {
            modeText = tr("LOOKUP MODE — scan a case to switch to its pallet", "MODO BÚSQUEDA — escanee una caja para cambiar a su pallet");
            modeColor = Color.rgb(147,197,253);
        }
        TextView mode = tv(modeText, 16, modeColor);
        mode.setGravity(Gravity.CENTER);
        body.addView(mode);

        TextView state = tv(tr("Current status: ", "Estado actual: ") + loadedPalletStatus, 14, Color.LTGRAY);
        state.setGravity(Gravity.CENTER);
        body.addView(state);
        addSpace(10);

        addCameraButtons();

        LinearLayout modes = new LinearLayout(this);
        modes.setOrientation(LinearLayout.HORIZONTAL);
        modes.setPadding(0, dp(4), 0, dp(8));
        Button lookup = button(tr("LOOKUP", "BUSCAR"), editMode == EDIT_LOOKUP ? Color.rgb(30,64,175) : Color.rgb(52,65,85));
        Button add = button(tr("+ ADD", "+ AÑADIR"), editMode == EDIT_ADD ? Color.rgb(22,101,52) : Color.rgb(52,65,85));
        Button remove = button(tr("− REMOVE", "− QUITAR"), editMode == EDIT_REMOVE ? Color.rgb(185,28,28) : Color.rgb(52,65,85));
        lookup.setOnClickListener(v -> { editMode = EDIT_LOOKUP; render(); });
        add.setOnClickListener(v -> { editMode = EDIT_ADD; render(); });
        remove.setOnClickListener(v -> { editMode = EDIT_REMOVE; render(); });
        modes.addView(lookup, new LinearLayout.LayoutParams(0, dp(54), 1));
        modes.addView(add, new LinearLayout.LayoutParams(0, dp(54), 1));
        modes.addView(remove, new LinearLayout.LayoutParams(0, dp(54), 1));
        body.addView(modes, new LinearLayout.LayoutParams(-1, -2));

        TextView listTitle = tv(tr("CASES ON PALLET", "CAJAS EN EL PALLET"), 13, Color.LTGRAY);
        listTitle.setTypeface(null, 1);
        body.addView(listTitle);
        LinearLayout list = new LinearLayout(this);
        list.setOrientation(LinearLayout.VERTICAL);
        list.setPadding(dp(14), dp(8), dp(14), dp(8));
        list.setBackgroundColor(Color.rgb(19,32,51));
        if (scannedCases.isEmpty()) {
            list.addView(tv(tr("No cases on this pallet", "No hay cajas en este pallet"), 14, Color.LTGRAY));
        } else {
            for (int i = scannedCases.size() - 1; i >= 0; i--) list.addView(tv("• " + scannedCases.get(i), 15, Color.WHITE));
        }
        body.addView(list, new LinearLayout.LayoutParams(-1, -2));
        addSpace(12);

        LinearLayout statusRow = new LinearLayout(this);
        statusRow.setOrientation(LinearLayout.HORIZONTAL);
        Button open = button("OPEN", Color.rgb(37,99,235));
        Button partial = button("PARTIAL", Color.rgb(180,83,9));
        Button complete = button("COMPLETE", Color.rgb(22,101,52));
        Button delete = button("DELETE", Color.rgb(185,28,28));
        open.setTextSize(12); partial.setTextSize(12); complete.setTextSize(12); delete.setTextSize(12);
        open.setOnClickListener(v -> saveStatus("OPEN", false, 0));
        partial.setOnClickListener(v -> showSaveChoice("PARTIAL"));
        complete.setOnClickListener(v -> showSaveChoice("COMPLETE"));
        delete.setOnClickListener(v -> showDeletePallet());
        statusRow.addView(open, new LinearLayout.LayoutParams(0, dp(54), 1));
        statusRow.addView(partial, new LinearLayout.LayoutParams(0, dp(54), 1));
        statusRow.addView(complete, new LinearLayout.LayoutParams(0, dp(54), 1));
        statusRow.addView(delete, new LinearLayout.LayoutParams(0, dp(54), 1));
        body.addView(statusRow, new LinearLayout.LayoutParams(-1, -2));
    }

    @Override void editPalletCase(String code) {
        if (!online) {
            error(tr("Editing requires a connection", "La modificación requiere conexión"));
            return;
        }
        if (editMode == EDIT_LOOKUP) {
            lookupCaseAndSwitch(code);
            return;
        }
        final String mode = editMode == EDIT_REMOVE ? "REMOVE" : "ADD";
        callUrl(BuildConfig.MODIFY_API_URL,
                map("action", "edit_case", "pallet_id", palletId, "case_serial", code,
                        "mode", mode, "password", modifyPassword), j -> {
                    caseCount = j.optInt("cases_count");
                    loadedPalletStatus = j.optString("status", "OPEN").toUpperCase();
                    loadCases(j);
                    editDirty = true;
                    ok((editMode == EDIT_REMOVE ? tr("Case removed: ", "Caja eliminada: ") : tr("Case added: ", "Caja añadida: ")) + code);
                    render();
                });
    }

    private void lookupCaseAndSwitch(String code) {
        callUrl(BuildConfig.MODIFY_API_URL,
                map("action", "lookup_case", "case_serial", code, "password", modifyPassword), j -> {
                    String target = j.optString("pallet_id", "");
                    if (target.isEmpty()) {
                        error(tr("This case is not assigned to a pallet", "Esta caja no está asignada a un pallet"));
                        return;
                    }
                    if (target.equalsIgnoreCase(palletId)) {
                        Toast.makeText(this, tr("Case is on the current pallet", "La caja está en el pallet actual") + " " + palletId, Toast.LENGTH_SHORT).show();
                        return;
                    }
                    if (editDirty) {
                        error(tr("Save the current pallet before switching", "Guarde el pallet actual antes de cambiar"));
                        return;
                    }
                    openPalletForEdit(target);
                });
    }

    private void showSaveChoice(final String status) {
        String title = tr("Save pallet as ", "Guardar pallet como ") + status;
        String[] choices = new String[]{tr("SAVE ONLY", "SOLO GUARDAR"), tr("PRINT LABEL", "IMPRIMIR ETIQUETA")};
        new AlertDialog.Builder(this)
                .setTitle(title)
                .setItems(choices, (d, which) -> {
                    if (which == 0) saveStatus(status, false, 0);
                    else choosePrinter(status);
                })
                .setNegativeButton(tr("Cancel", "Cancelar"), null)
                .show();
    }

    private void choosePrinter(final String status) {
        callUrl(BuildConfig.MODIFY_API_URL, map("action", "printers"), j -> {
            JSONArray printers = j.optJSONArray("printers");
            if (printers == null || printers.length() == 0) {
                error(tr("No active pallet label printers are configured in the webapp", "No hay impresoras de etiquetas activas configuradas en la webapp"));
                return;
            }
            List<String> names = new ArrayList<>();
            List<Integer> ids = new ArrayList<>();
            int remembered = modifyPrefs().getInt("last_printer_id", 0);
            int checked = -1;
            for (int i = 0; i < printers.length(); i++) {
                JSONObject p = printers.optJSONObject(i);
                if (p == null) continue;
                int id = p.optInt("id");
                ids.add(id);
                names.add(p.optString("name", "Printer " + id));
                if (id == remembered) checked = ids.size() - 1;
            }
            final int initial = checked;
            final AlertDialog dialog = new AlertDialog.Builder(this)
                    .setTitle(tr("Choose printer", "Seleccione impresora"))
                    .setSingleChoiceItems(names.toArray(new String[0]), initial, null)
                    .setNegativeButton(tr("Cancel", "Cancelar"), null)
                    .setPositiveButton(tr("PRINT", "IMPRIMIR"), null)
                    .create();
            dialog.setOnShowListener(x -> dialog.getButton(AlertDialog.BUTTON_POSITIVE).setOnClickListener(v -> {
                int selected = dialog.getListView().getCheckedItemPosition();
                if (selected < 0 || selected >= ids.size()) {
                    Toast.makeText(this, tr("Choose a printer", "Seleccione una impresora"), Toast.LENGTH_SHORT).show();
                    return;
                }
                int printerId = ids.get(selected);
                modifyPrefs().edit().putInt("last_printer_id", printerId).apply();
                dialog.dismiss();
                saveStatus(status, true, printerId);
            }));
            dialog.show();
        });
    }

    private void saveStatus(String status, boolean print, int printerId) {
        if (!online) {
            error(tr("Connect before saving", "Conéctese antes de guardar"));
            return;
        }
        callUrl(BuildConfig.MODIFY_API_URL,
                map("action", "set_status", "pallet_id", palletId, "status", status,
                        "print_label", print ? "1" : "0", "printer_id", String.valueOf(printerId),
                        "password", modifyPassword), j -> {
                    String message = "Pallet " + palletId + " → " + status;
                    if (print) message += j.optInt("label_printed", 0) == 1 ? tr(" · label sent", " · etiqueta enviada") : tr(" · label NOT sent", " · etiqueta NO enviada");
                    done(message);
                });
    }

    private void showDeletePallet() {
        new AlertDialog.Builder(this)
                .setTitle(tr("DELETE pallet", "ELIMINAR pallet"))
                .setMessage(tr("Delete pallet ", "Eliminar pallet ") + palletId + "?\n\n" +
                        caseCount + " " + tr("cases will be released and can be palletized again. This does not delete the casecodes.", "cajas quedarán libres y podrán paletizarse de nuevo. No se eliminan los casecodes."))
                .setNegativeButton(tr("Cancel", "Cancelar"), null)
                .setPositiveButton("DELETE", (d, w) -> askDeletePassword())
                .show();
    }

    private void askDeletePassword() {
        final EditText input = new EditText(this);
        input.setInputType(InputType.TYPE_CLASS_NUMBER | InputType.TYPE_NUMBER_VARIATION_PASSWORD);
        final AlertDialog dialog = new AlertDialog.Builder(this)
                .setTitle(tr("DELETE protected", "ELIMINACIÓN protegida"))
                .setMessage(tr("Enter the protected PIN to delete ", "Introduzca el PIN protegido para eliminar ") + palletId)
                .setView(input)
                .setNegativeButton(tr("Cancel", "Cancelar"), null)
                .setPositiveButton("DELETE", null)
                .create();
        dialog.setOnShowListener(x -> dialog.getButton(AlertDialog.BUTTON_POSITIVE).setOnClickListener(v -> {
            String pin = input.getText().toString().trim();
            if (pin.isEmpty()) return;
            callUrl(BuildConfig.MODIFY_API_URL,
                    map("action", "delete_pallet", "pallet_id", palletId, "password", pin), j -> {
                        dialog.dismiss();
                        done(tr("Pallet deleted. Cases are available again.", "Pallet eliminado. Las cajas están disponibles de nuevo."));
                    });
        }));
        dialog.show();
    }

    @Override void resetHome() {
        editMode = EDIT_LOOKUP;
        editDirty = false;
        modifyPassword = "";
        loadedPalletStatus = "";
        super.resetHome();
    }
}
