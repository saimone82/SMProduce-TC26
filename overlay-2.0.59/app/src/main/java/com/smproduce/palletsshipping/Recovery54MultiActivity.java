package com.smproduce.palletsshipping;

import android.app.AlertDialog;
import android.content.DialogInterface;
import android.text.InputType;
import android.view.View;
import android.widget.ArrayAdapter;
import android.widget.EditText;
import android.widget.Spinner;
import android.widget.Toast;

import org.json.JSONArray;
import org.json.JSONObject;

import java.lang.reflect.Field;
import java.lang.reflect.Method;
import java.util.ArrayList;
import java.util.LinkedHashMap;
import java.util.Map;

/**
 * 2.0.60 additive overlay.
 *
 * Keeps the exact 2.0.59 / Recovery53 UI and changes only pallet completion:
 * before a pallet becomes COMPLETE, Pallet Rules is checked and any override
 * must explain the exact reason before the operator can enter the 4-digit PIN.
 */
public class Recovery54MultiActivity extends Recovery53MultiActivity {
    private boolean completeCloseBusy = false;
    private boolean editedClose = false;
    private String completeClientId = "";
    private String completeSourceToken = "";

    private interface Reply {
        void run(JSONObject value);
    }

    private Field deepField(String name) throws Exception {
        Class<?> c = getClass();
        while (c != null) {
            try {
                Field f = c.getDeclaredField(name);
                f.setAccessible(true);
                return f;
            } catch (NoSuchFieldException ignored) {
                c = c.getSuperclass();
            }
        }
        throw new NoSuchFieldException(name);
    }

    private Method deepMethod(String name, int parameterCount) throws Exception {
        Class<?> c = getClass();
        while (c != null) {
            for (Method m : c.getDeclaredMethods()) {
                if (m.getName().equals(name) && m.getParameterTypes().length == parameterCount) {
                    m.setAccessible(true);
                    return m;
                }
            }
            c = c.getSuperclass();
        }
        throw new NoSuchMethodException(name);
    }

    private Object inherited(String name) throws Exception {
        return deepField(name).get(this);
    }

    private void inherited(String name, Object value) {
        try { deepField(name).set(this, value); } catch (Exception ignored) {}
    }

    private String palletId() {
        try { return String.valueOf(inherited("palletId")); }
        catch (Exception e) { return ""; }
    }

    private boolean isOnline() {
        try { return deepField("online").getBoolean(this); }
        catch (Exception e) { return true; }
    }

    private int queuedFor(String pid) {
        try {
            Object queue = inherited("queue");
            if (queue == null) return 0;
            Method m = queue.getClass().getDeclaredMethod("countFor", String.class);
            m.setAccessible(true);
            Object value = m.invoke(queue, pid);
            return value instanceof Number ? ((Number)value).intValue() : 0;
        } catch (Exception e) {
            return 0;
        }
    }

    private void busy(boolean value) {
        completeCloseBusy = value;
        inherited("busy", value);
    }

    private void attention(String message) {
        new AlertDialog.Builder(this)
                .setTitle("Attention")
                .setMessage(message)
                .setPositiveButton("OK", null)
                .show();
    }

    private JSONObject requestMain(Map<String,String> data) throws Exception {
        Method request = deepMethod("request", 1);
        return (JSONObject)request.invoke(this, data);
    }

    private void api(final Map<String,String> data, final Reply reply) {
        new Thread(() -> {
            JSONObject result = null;
            String error = null;
            try {
                result = requestMain(data);
            } catch (Exception e) {
                Throwable cause = e.getCause() != null ? e.getCause() : e;
                error = "Connection error: " + (cause.getMessage() == null ? cause.getClass().getSimpleName() : cause.getMessage());
            }
            final JSONObject response = result;
            final String problem = error;
            runOnUiThread(() -> {
                if (isFinishing()) return;
                if (problem != null) {
                    busy(false);
                    attention(problem);
                    return;
                }
                reply.run(response == null ? new JSONObject() : response);
            });
        }).start();
    }

    private void doneMessage(String message) {
        busy(false);
        try {
            deepMethod("done", 1).invoke(this, message);
        } catch (Exception e) {
            Toast.makeText(this, message, Toast.LENGTH_LONG).show();
            try { deepMethod("render", 0).invoke(this); } catch (Exception ignored) {}
        }
    }

    private boolean canFinish(String pid) {
        if (!isOnline()) {
            attention("Connect before finishing this pallet.");
            return false;
        }
        if (queuedFor(pid) > 0) {
            attention("Wait for synchronization before finishing this pallet.");
            return false;
        }
        return true;
    }

    private String product(JSONObject check) {
        ArrayList<String> values = new ArrayList<>();
        String variety = check.optString("variety", "").trim();
        String size = check.optString("size", "").trim();
        String packaging = check.optString("packaging", "").trim();
        if (!variety.isEmpty()) values.add(variety);
        if (!size.isEmpty()) values.add(size);
        if (!packaging.isEmpty()) values.add(packaging);
        return android.text.TextUtils.join(" · ", values);
    }

    private String explanation(JSONObject context) {
        StringBuilder out = new StringBuilder();
        out.append("WHY OVERRIDE IS REQUIRED\n\n");
        out.append("You are trying to close this pallet as COMPLETE, but its case count does not match Pallet Rules.\n\n");
        out.append("Pallet: ").append(palletId()).append("\n");

        String customer = context.optString("customer_name", "").trim();
        if (!customer.isEmpty()) out.append("Customer: ").append(customer).append("\n");

        JSONArray reasons = context.optJSONArray("override_reasons");
        if (reasons != null && reasons.length() > 0) {
            out.append("\n");
            for (int i=0; i<reasons.length(); i++) {
                String reason = reasons.optString(i, "").trim();
                if (!reason.isEmpty()) out.append("• ").append(reason).append("\n");
            }
        } else {
            JSONArray checks = context.optJSONArray("mismatches");
            if (checks != null && checks.length() > 0) {
                out.append("\n");
                for (int i=0; i<checks.length(); i++) {
                    JSONObject c = checks.optJSONObject(i);
                    if (c == null) continue;
                    int actual = c.optInt("actual", 0);
                    int required = c.optInt("required", 0);
                    int delta = actual - required;
                    String source = "customer".equalsIgnoreCase(c.optString("source", ""))
                            ? "customer rule" : "SKU standard";

                    out.append("• SKU ").append(c.optInt("sku_id", 0));
                    String product = product(c);
                    if (!product.isEmpty()) out.append(" (").append(product).append(")");
                    out.append(": ").append(actual).append(" cases loaded; ")
                            .append(source).append(" requires ").append(required).append(". ");

                    if (delta < 0) out.append(Math.abs(delta)).append(" case(s) short.");
                    else out.append(delta).append(" case(s) over.");
                    out.append("\n");
                }
            }
        }

        out.append("\nIf you continue, the pallet will be closed as an AUTHORIZED EXCEPTION and the override will be recorded.");
        out.append("\n\nEnter 2424 only if you intentionally accept this exception.");
        return out.toString();
    }

    private void chooseCustomer(JSONObject context) {
        JSONArray customers = context.optJSONArray("customers");
        if (customers == null || customers.length() == 0) {
            busy(false);
            attention("No customer is available for Pallet Rules. Configure the customer first.");
            return;
        }

        ArrayList<String> names = new ArrayList<>();
        names.add("Choose customer");
        for (int i=0; i<customers.length(); i++) {
            JSONObject c = customers.optJSONObject(i);
            names.add(c == null ? "" : c.optString("client_name", ""));
        }

        Spinner spinner = new Spinner(this);
        ArrayAdapter<String> adapter = new ArrayAdapter<>(this, android.R.layout.simple_spinner_item, names);
        adapter.setDropDownViewResource(android.R.layout.simple_spinner_dropdown_item);
        spinner.setAdapter(adapter);

        AlertDialog dialog = new AlertDialog.Builder(this)
                .setTitle("Pallet customer")
                .setMessage("Select the customer so Pallet Rules can determine the required cases for this COMPLETE pallet.")
                .setView(spinner)
                .setNegativeButton("Back to pallet", (d,w) -> busy(false))
                .setPositiveButton("Continue", null)
                .setCancelable(false)
                .create();

        dialog.setOnShowListener(x -> dialog.getButton(AlertDialog.BUTTON_POSITIVE).setOnClickListener(v -> {
            int index = spinner.getSelectedItemPosition() - 1;
            if (index < 0) {
                Toast.makeText(this, "Choose a customer", Toast.LENGTH_SHORT).show();
                return;
            }
            JSONObject c = customers.optJSONObject(index);
            if (c == null) return;
            completeClientId = c.optString("id", "");
            dialog.dismiss();
            loadContext();
        }));
        dialog.show();
    }

    private void password(JSONObject context) {
        EditText input = new EditText(this);
        input.setInputType(InputType.TYPE_CLASS_NUMBER | InputType.TYPE_NUMBER_VARIATION_PASSWORD);
        input.setHint("2424");

        AlertDialog dialog = new AlertDialog.Builder(this)
                .setTitle("Authorize COMPLETE exception")
                .setMessage("This override will be written to the pallet exception audit. Enter the 4-digit override password.")
                .setView(input)
                .setNegativeButton("Back to pallet", (d,w) -> busy(false))
                .setPositiveButton("Override and Close", null)
                .setCancelable(false)
                .create();

        dialog.setOnShowListener(x -> dialog.getButton(AlertDialog.BUTTON_POSITIVE).setOnClickListener(v -> {
            String value = input.getText().toString().trim();
            if (value.length() != 4) {
                input.setError("Enter the 4-digit override password");
                return;
            }
            dialog.dismiss();
            closeComplete(value);
        }));
        dialog.show();
    }

    private void review(JSONObject context) {
        if (context.optBoolean("needs_customer", false)) {
            chooseCustomer(context);
            return;
        }

        completeClientId = context.optString("client_id", completeClientId);
        completeSourceToken = context.optString("source_token", "");

        if (!context.optBoolean("override_required", false)) {
            closeComplete("");
            return;
        }

        new AlertDialog.Builder(this)
                .setTitle("Complete pallet override required")
                .setMessage(explanation(context))
                .setNegativeButton("Back to pallet", (d,w) -> busy(false))
                .setPositiveButton("Override with password", (d,w) -> password(context))
                .setCancelable(false)
                .show();
    }

    private void loadContext() {
        Map<String,String> data = new LinkedHashMap<>();
        data.put("action", "pallet_complete_context");
        data.put("pallet_id", palletId());
        if (!completeClientId.isEmpty()) data.put("complete_client_id", completeClientId);

        api(data, response -> {
            if (response.optInt("ok", 0) != 1 && !response.optBoolean("ok", false)) {
                busy(false);
                attention(response.optString("err", "Unable to check Pallet Rules."));
                return;
            }
            review(response);
        });
    }

    private void closeComplete(String password) {
        Map<String,String> data = new LinkedHashMap<>();
        data.put("action", "pallet_close");
        data.put("pallet_id", palletId());
        if (!completeClientId.isEmpty()) data.put("complete_client_id", completeClientId);
        if (!completeSourceToken.isEmpty()) data.put("complete_source_token", completeSourceToken);
        if (password != null && !password.isEmpty()) data.put("complete_password", password);

        api(data, response -> {
            if (response.optInt("ok", 0) != 1 && !response.optBoolean("ok", false)) {
                JSONObject updated = response.optJSONObject("complete_context");
                String message = response.optString("err", "Pallet is still open.");
                if (updated != null) {
                    new AlertDialog.Builder(this)
                            .setTitle("Pallet still open")
                            .setMessage(message)
                            .setNegativeButton("Back to pallet", (d,w) -> busy(false))
                            .setPositiveButton("Review again", (d,w) -> loadContext())
                            .setCancelable(false)
                            .show();
                } else {
                    busy(false);
                    attention(message);
                }
                return;
            }

            if (response.optInt("label_printed", 0) != 1) {
                busy(false);
                attention("Pallet was closed, but the label was not sent. Check the printer selected in Pallets Manage.");
                return;
            }

            doneMessage(editedClose
                    ? "Pallet updated, closed and sent to print"
                    : "Pallet closed as complete and sent to print");
        });
    }

    void finishPallet(boolean complete) {
        if (completeCloseBusy) return;
        String pid = palletId();
        if (pid == null || pid.trim().isEmpty()) {
            attention("No pallet is selected.");
            return;
        }
        if (!canFinish(pid)) return;

        busy(true);
        editedClose = false;
        completeClientId = "";
        completeSourceToken = "";

        if (!complete) {
            Map<String,String> data = new LinkedHashMap<>();
            data.put("action", "pallet_partial");
            data.put("pallet_id", pid);
            api(data, response -> {
                if (response.optInt("ok", 0) != 1 && !response.optBoolean("ok", false)) {
                    busy(false);
                    attention(response.optString("err", "Unable to close pallet as Partial."));
                    return;
                }
                if (response.optInt("label_printed", 0) != 1) {
                    busy(false);
                    attention("Pallet was saved as Partial, but the label was not sent. Check the printer selected in Pallets Manage.");
                    return;
                }
                doneMessage("Pallet closed as partial and sent to print");
            });
            return;
        }

        loadContext();
    }

    void finishEditedPallet() {
        if (completeCloseBusy) return;
        String pid = palletId();
        if (pid == null || pid.trim().isEmpty()) {
            attention("No pallet is selected.");
            return;
        }
        if (!canFinish(pid)) return;

        busy(true);
        editedClose = true;
        completeClientId = "";
        completeSourceToken = "";
        loadContext();
    }
}
