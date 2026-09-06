package com.smproduce.palletsshipping;

import android.content.ContentValues;
import android.content.Context;
import android.database.Cursor;
import android.database.sqlite.SQLiteDatabase;
import android.database.sqlite.SQLiteDatabase;
import android.database.sqlite.SQLiteOpenHelper;
import java.util.ArrayList;
import java.util.List;

class MainActivity$QueueDb extends SQLiteOpenHelper {
    MainActivity$QueueDb(Context context) {
        super(context, "pallets_shipping_offline.db", (SQLiteDatabase.CursorFactory) null, 1);
    }

    boolean add(String str, String str2, String str3) {
        ContentValues contentValues = new ContentValues();
        contentValues.put("type", str);
        contentValues.put("parent", str2);
        contentValues.put("code", str3);
        contentValues.put("created", Long.valueOf(System.currentTimeMillis()));
        return getWritableDatabase().insertWithOnConflict("queue", null, contentValues, 4) != -1;
    }

    List<MainActivity$QueueDb$Item> all() {
        ArrayList arrayList = new ArrayList();
        Cursor cursorRawQuery = getReadableDatabase().rawQuery("SELECT id,type,parent,code FROM queue ORDER BY id", null);
        while (cursorRawQuery.moveToNext()) {
            try {
                arrayList.add(new MainActivity$QueueDb$Item(cursorRawQuery.getLong(0), cursorRawQuery.getString(1), cursorRawQuery.getString(2), cursorRawQuery.getString(3)));
            } catch (Throwable th) {
                if (cursorRawQuery != null) {
                    try {
                        cursorRawQuery.close();
                    } catch (Throwable th2) {
                        th.addSuppressed(th2);
                    }
                }
                throw th;
            }
        }
        if (cursorRawQuery != null) {
            cursorRawQuery.close();
        }
        return arrayList;
    }

    int countFor(String str) {
        Cursor cursorRawQuery = getReadableDatabase().rawQuery("SELECT COUNT(*) FROM queue WHERE parent=?", new String[]{str});
        try {
            int i = cursorRawQuery.moveToFirst() ? cursorRawQuery.getInt(0) : 0;
            if (cursorRawQuery != null) {
                cursorRawQuery.close();
            }
            return i;
        } catch (Throwable th) {
            if (cursorRawQuery != null) {
                try {
                    cursorRawQuery.close();
                } catch (Throwable th2) {
                    th.addSuppressed(th2);
                }
            }
            throw th;
        }
    }

    boolean exists(String str, String str2, String str3) {
        Cursor cursorRawQuery = getReadableDatabase().rawQuery("SELECT 1 FROM queue WHERE type=? AND parent=? AND code=? LIMIT 1", new String[]{str, str2, str3});
        try {
            boolean zMoveToFirst = cursorRawQuery.moveToFirst();
            if (cursorRawQuery != null) {
                cursorRawQuery.close();
            }
            return zMoveToFirst;
        } catch (Throwable th) {
            if (cursorRawQuery != null) {
                try {
                    cursorRawQuery.close();
                } catch (Throwable th2) {
                    th.addSuppressed(th2);
                }
            }
            throw th;
        }
    }

    @Override
    public void onCreate(SQLiteDatabase sQLiteDatabase) {
        sQLiteDatabase.execSQL("CREATE TABLE queue(id INTEGER PRIMARY KEY AUTOINCREMENT,type TEXT,parent TEXT,code TEXT,created INTEGER,UNIQUE(type,parent,code))");
    }

    @Override
    public void onUpgrade(SQLiteDatabase sQLiteDatabase, int i, int i2) {
    }

    void remove(long j) {
        getWritableDatabase().delete("queue", "id=?", new String[]{String.valueOf(j)});
    }

    boolean removeLast(String str, String str2) {
        Cursor cursorRawQuery = getReadableDatabase().rawQuery("SELECT id FROM queue WHERE type=? AND parent=? ORDER BY id DESC LIMIT 1", new String[]{str, str2});
        try {
            if (!cursorRawQuery.moveToFirst()) {
                if (cursorRawQuery != null) {
                    cursorRawQuery.close();
                }
                return false;
            }
            remove(cursorRawQuery.getLong(0));
            if (cursorRawQuery != null) {
                cursorRawQuery.close();
            }
            return true;
        } catch (Throwable th) {
            if (cursorRawQuery != null) {
                try {
                    cursorRawQuery.close();
                } catch (Throwable th2) {
                    th.addSuppressed(th2);
                }
            }
            throw th;
        }
    }

    void replaceParent(String str, String str2) {
        ContentValues contentValues = new ContentValues();
        contentValues.put("parent", str2);
        getWritableDatabase().update("queue", contentValues, "parent=?", new String[]{str});
    }
}
